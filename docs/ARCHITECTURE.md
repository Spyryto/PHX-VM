# PHX-VM: architettura e formato v0.1

## Obiettivo

Macchina virtuale PHP-first, indipendente da Nail e da qualunque linguaggio ospitato. La rappresentazione del programma non impone currying, immutabilità, aritmetica arbitraria né uno specifico AST.

~~~text
Linguaggio / IR
      |
      v
Program PHX verificato
      |
      +--> T0: interprete a registri     [implementato]
      +--> T1: blocchi predecodificati    [prototipo]
      +--> T2: PHP diretto a funzioni     [prototipo]
~~~

## Formato di istruzione

Ogni istruzione usa una parola non segnata da 32 bit, memorizzata oggi come int PHP. Sono supportati due layout:

~~~text
31        24 23        16 15         8 7          0
+-----------+------------+------------+------------+
| C (8 bit) | B (8 bit)  | A (8 bit)  | OP (8 bit) |
+-----------+------------+------------+------------+

ABx = OP:8 | A:8 | Bx:16
~~~

Nel formato ABx, Bx occupa i bit 16..31. I salti sono indici assoluti delle istruzioni della funzione, non offset relativi. La rappresentazione v0.1 richiede PHP con interi host a 64 bit.

| Opcode | Operandi | Semantica |
| --- | --- | --- |
| CONST | A, Bx | rA = constants[Bx] |
| MOVE | A, B | rA = rB |
| IADD / ISUB / IMUL | A, B, C | interi PHP esatti con eccezione su overflow |
| ILT / IEQ | A, B, C | confronto di interi, restituisce bool |
| JMP | Bx | salto incondizionato |
| BR_TRUE / BR_FALSE | A, Bx | salto condizionato al registro bool A |
| CALL | A, B, C | rA = functions[B](rC...r(C+arity-1)) |
| TAILCALL | B, C | trasferisce il controllo a functions[B], riusando l'attivazione |
| RET | A | restituisce rA |

Gli operandi riservati devono essere zero, inclusi A per JMP e TAILCALL, e B/C per RET. Le funzioni hanno fino a 256 registri tipizzati, argomenti nei primi arity registri, e una firma del tipo di ritorno. Il limite iniziale è 256 funzioni, 65.536 istruzioni/funzione e 65.536 costanti/modulo.

## Contratti di correttezza

1. **Tipi stretti.** Nessuna coercizione PHP implicita. I tipi iniziali sono int, float, bool, string e null; il nucleo v0.1 implementa la sola aritmetica intera e confronti interi.
2. **Overflow rilevato.** L'addizione, sottrazione e moltiplicazione di int PHP non cambiano implicitamente dominio in float. Se il risultato non è un intero, la VM solleva VmFault. Questi int non sono gli Int arbitrariamente grandi di Nail.
3. **Verifica prima dell'esecuzione.** Ogni immagine viene controllata per codifica, opcode, destinazioni dei salti, tipi, ABI delle chiamate e inizializzazione dei registri.
4. **Control flow.** Il verificatore risolve l'assegnazione definita con un'analisi iterativa a punto fisso: alle confluenze si usa l'intersezione dei registri sicuramente inizializzati.
5. **Niente ricorsione nell'host.** CALL salva frame espliciti nella VM, mentre TAILCALL sostituisce quello corrente. La ricorsione generale può comunque richiedere memoria proporzionale alla profondità logica.
6. **Budget.** L'interprete carica al massimo maxSteps istruzioni; il contatore include anche CALL, TAILCALL e salti. È un budget di istruzioni, non di memoria o tempo reale.
7. **Nessuna capability ambientale.** v0.1 non offre IO, funzioni PHP arbitrarie, caricamento di file o eval. In futuro gli host call richiederanno capability esplicite.

## Specializzazione futura

**T1 (prototipo):** BlockCompiler verifica il programma e prepara per ogni funzione una mappa da PC originale a basic block con istruzioni [opcode,A,B,C] già decodificate. I leader comprendono ingresso, destinazioni dei salti, fallthrough e punti di ripresa dopo CALL. BlockVm esegue un blocco per volta, mantiene PC e frame equivalenti a T0, e carica un'unità di budget per ogni istruzione originale. Il ciclo interno utilizza ancora uno switch per opcode; pertanto questa versione non elimina il dispatch per istruzione e non promette un guadagno di velocità. Superistruzioni e dispatch veramente fuso sono ancora futuri. Il bytecode persistente rimane invariato.

**T1 con superistruzioni (sperimentale):** la modalità opzionale new BlockVm($program, true) prepara istruzioni sintetiche, non serializzabili, soltanto all'interno di un basic block verificato. I primi pattern sono IEQ/ILT + BR_TRUE/BR_FALSE (se il registro condizione coincide con il risultato del confronto) e IADD + ISUB. Ogni operazione fusa esegue i due effetti nell'ordine canonico e controlla il budget separatamente prima della seconda istruzione; errori, lastSteps, branching e registri rimangono equivalenti a T0. L'interprete ha ora un handler per coppia fusa e riduce un dispatch per coppia eseguita, ma non è ancora un compilatore PHP. Fondere tra due leader di blocco, compresi i punti di ripresa di CALL, è vietato. Il benchmark confronta T0, T1 predecodificato e T1 fuso senza imporre soglie prestazionali.

**T2 (primo prototipo):** `PhpEmitter::source(Program $program, int $id)` verifica il programma e genera sorgente PHP 8.2+ per una sola funzione priva di CALL/TAILCALL. Ogni registro diventa una variabile locale; i branch e i loop diventano `goto` a etichette nello stesso scope, senza switch né dispatch del bytecode. Per ogni istruzione originale emette il controllo del budget prima dell'effetto; `finally` aggiorna lastSteps anche quando un'operazione genera eccezione. L'aritmetica conserva i controlli di overflow e i tipi esatti del runtime originale. Le costanti sono serializzate con var_export come literal PHP. `PhpVm` carica un file contenente *soltanto output fidato del compilatore* e lo richiama mantenendo l'API run/lastSteps. Al momento le chiamate di funzioni sono rifiutate esplicitamente, non convertite in modo approssimativo. Non c'è JIT, non c'è sandbox e non c'è ancora ricostruzione automatica di `while` strutturati; le etichette rappresentano fedelmente il CFG originale.

**Limite del benchmark T2:** `bench/compare.php` misura l'esecuzione della closure PHP dopo compilazione e caricamento; non include tempi di generazione del sorgente, creazione file, require, warmup OPcache cross-request o memoria del codice emesso. Va quindi affiancato in futuro da benchmark end-to-end e test con OPcache attivo in processi persistenti.

**T2 interprocedurale (prototipo):** le funzioni con `CALL` o `TAILCALL` sono emesse in una singola closure PHP insieme alla chiusura transitiva dei destinatari diretti. Ogni funzione compilata possiede etichette univoche `F<id>_B<pc>`; il trasferimento al callee è un `goto` diretto, non una chiamata ricorsiva PHP. `CALL` salva una continuazione esplicita con identificatore del callsite e tutti i registri del chiamante; `RET` ripristina i registri, scrive il risultato nel registro destinazione, e salta all'istruzione successiva del chiamante tramite un unico dispatch sulle continuazioni. `TAILCALL` riutilizza l'attivazione corrente e non aggiunge frame. Prima di ogni passaggio gli argomenti vengono valutati contemporaneamente, impedendo errori quando la finestra sorgente si sovrappone a quella destinazione. Tutte le variabili registro vengono inizializzate su ingresso e sulle chiamate. L'esecutore conta ciascuna istruzione canonica esattamente una volta, anche quando viene interrotta dal budget o da un overflow.

Per non rallentare il caso comune, il backend **mantiene il precedente percorso T2 senza chiamate** quando la funzione d'ingresso non usa `CALL/TAILCALL`. La ricorsione non terminale richiede memoria per le continuazioni proporzionale alla profondità logica; non viene convertita automaticamente in un algoritmo iterativo a memoria costante. Non è presente un modello di closure o chiamata indiretta: sono funzionalità future.

**T2 inlining selettivo (opt-in):** `PhpEmitter::source($program, $entry, true)` abilita l'incorporazione di funzioni *leaf* senza salti, chiamate annidate o ritorni anticipati, lunghe al massimo 8 istruzioni canoniche. Ogni callsite incorpora un insieme di registri PHP privati, un'inizializzazione simultanea degli argomenti e le istruzioni del callee nello stesso ordine originale; `CALL` non crea frame e il `RET` finale scrive direttamente nel registro di destinazione. `TAILCALL` restituisce il risultato alla continuazione preesistente, senza allocare frame aggiuntivi. Il controllo del budget precede ogni operazione come in T0: la CALL e ciascuna operazione inlined, incluso RET, hanno costo unitario; errori e conteggio rimangono confrontabili anche sui limiti del budget. Per contenere la crescita del sorgente sono duplicabili al massimo 128 istruzioni originali nell'intero modulo compilato. Funzioni ricorsive, con branching o troppo grandi mantengono il percorso tradizionale. L'ottimizzazione è disabilitata per default ed è specifica del backend PHP, senza alterare il bytecode canonico né introdurre dipendenze da Nail.

**Benchmark inlining:** `php bench/inline.php 20000` misura, sullo stesso bytecode che chiama una funzione foglia in un ciclo, T0 e T2 con/senza inlining. Espone separatamente generazione sorgente, caricamento e mediana del runtime; non misura il riuso OPcache cross-request e non impone alcuna soglia di speedup.

**Supporto funzionale:** closure, ambienti catturati, chiamate indirette specializzate, applicazione parziale e pattern matching. Queste capacità non devono dipendere da Nail.

**Dati e semantiche:** valori strutturati, interi arbitrari, float, record, rappresentazioni di stringhe/array, gestione effetti e capability dovranno essere specificati prima di ottimizzarli.

## Misurazione

Il benchmark non assertivo bench/compare.php confronta ora T0 e T1 sullo stesso programma di somma iterativa verificato, stampa le mediane di sette prove, la versione PHP, l'impostazione OPcache/JIT e il rapporto dei tempi. Non include costi di preparazione del programma e non stabilisce un guadagno generale. Per dichiarare un miglioramento occorrono workload differenti, controllo del rumore, benchmark con costi di preparazione e confronto con PHP nativo semanticamente equivalente.

## Uso

~~~sh
php tests/run.php
php tests/t1.php
php tests/fusion.php
php tests/t2.php
php tests/t2-calls.php
php tests/inlining.php
php examples/sum-tail.php 10000
php bench/compare.php 30000
php bench/inline.php 20000
~~~

Nessuna licenza è stata ancora selezionata.
