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

**T2 continuazioni compatte (opt-in):** l'analisi di liveness `RegisterLiveness::acrossCalls` usa una propagazione all'indietro del flusso di dati su tutto il CFG verificato, compresi salti, confluenze e cicli. Per ogni `CALL` trova i registri che potrebbero essere letti dopo il ritorno, prima di essere sovrascritti. Esclude il registro di destinazione perché riceve il valore restituito. Il backend `CallPhpEmitter` può quindi emettere una continuazione contenente solo questi registri, invece di copiare tutto il banco del chiamante. Un insieme vuoto è legittimo: la continuazione conserva comunque il punto di ritorno, ma nessun valore del banco. `TAILCALL` non aggiunge mai un frame. L'analisi è intraprocedurale ma tiene conto delle letture/scritture alle altre chiamate; non serve analisi interprocedurale per sapere quali registri del chiamante sopravvivono. La condizione di correttezza è mantenere identici i risultati osservabili, il numero di istruzioni originarie eseguite, il punto e tipo di errore, e non leggere mai registri rimasti non ripristinati. La compilazione è disponibile tramite `PhpEmitter::source($program, $entry, $inline, true)`; l'opzione rimane disabilitata per default. Il bytecode canonico non cambia e l'inlining esistente è compatibile.

**Benchmark delle continuazioni:** `php bench/frames.php 2000` confronta una ricorsione non terminale con 64 registri per frame, di cui solo uno vivo dopo la chiamata. Misura separatamente T0, T2 completo e T2 con liveness, costi di generazione/caricamento e dimensione del sorgente PHP. Il numero di slot salvati non è una misura della memoria fisica del processo e non implica automaticamente un vantaggio in altri programmi.

**Supporto funzionale:** closure, ambienti catturati, chiamate indirette specializzate, applicazione parziale e pattern matching. Queste capacità non devono dipendere da Nail.

**Dati e semantiche:** valori strutturati, interi arbitrari, float, record, rappresentazioni di stringhe/array, gestione effetti e capability dovranno essere specificati prima di ottimizzarli.

## Esperimento end-to-end e OPcache

Il benchmark `bench/end-to-end.php` separa esplicitamente:

- **T0 nuovo per ogni run:** costruzione/verifica + prima esecuzione;
- **T0 caldo:** esecuzione su istanza già verificata;
- **T2 freddo:** verifier e generazione PHP, scrittura di un nuovo file, `require`, prima esecuzione;
- **T2 caldo:** ripetizione della closure già caricata;
- **T2 path stabile:** `require` di un artefatto immutabile identificato da SHA-256 del sorgente, seguito dall'esecuzione, più raccolta telemetria OPcache ove disponibile.

Misura almeno due workload distinti, uno senza chiamate e uno con invocazioni frequenti di una funzione leaf, con e senza inlining. Ogni risultato e numero di istruzioni canoniche viene confrontato con T0. I tempi mediani dei componenti sono calcolati separatamente e non devono essere sommati come se appartenessero alla stessa prova temporale.

Il percorso con file stabile è intenzionalmente **content-addressed**: un file con hash noto non viene sovrascritto; se il contenuto presente ha hash diverso la misurazione fallisce invece di richiedere codice estraneo. La directory deve essere privata e controllata dall'esecutore. I file temporanei del percorso freddo sono sempre nuovi e rimossi dopo il caricamento.

### Cosa dimostra OPcache, e cosa no

Il riuso di una closure non dimostra OPcache: equivale solo a non ricompilare codice già in memoria. Un `require` ripetuto nello stesso processo può sfruttare OPcache quando la CLI è esplicitamente abilitata e la telemetria `opcache_is_script_cached` e gli hit lo confermano; non è un benchmark FPM. Due processi CLI possono condividere una directory `opcache.file_cache`, ma il semplice fatto di trovare nuovamente il file non prova che il bytecode sia stato caricato dalla cache su disco; l'API di stato può inoltre variare con `opcache.file_cache_only` e la configurazione del runtime.

Un controllo riproducibile con file hashati e privati può usare:

~~~sh
mkdir -p /tmp/phx-vm-artifacts /tmp/phx-vm-opcache
php -d opcache.enable_cli=1 -d opcache.file_cache=/tmp/phx-vm-opcache -d opcache.file_update_protection=0 bench/end-to-end.php 2000 7 /tmp/phx-vm-artifacts
php -d opcache.enable_cli=1 -d opcache.file_cache=/tmp/phx-vm-opcache -d opcache.file_update_protection=0 bench/end-to-end.php 2000 7 /tmp/phx-vm-artifacts
~~~

La protezione per file recenti (`opcache.file_update_protection`) può impedire la cache di un file appena generato; il valore zero qui è soltanto un controllo sperimentale su file immutabili, **non** una raccomandazione di configurazione globale. Il benchmark non include bootstrap CLI, process spawning, richieste HTTP, rete, FPM, cache warm di un server persistente o utilizzo reale di memoria. Sono misure future.

## Profiler e tiering consultivo (PR #8)

Il profiler `ExecutionProfile` è un componente opzionale dell'interprete canonico T0: `new Vm($program, $profile)`. Non altera la codifica del bytecode e non esegue alcun codice generato. Raccoglie il numero di ingressi per funzione, gli opcode caricati nel budget, i trasferimenti `CALL` e `TAILCALL`, i ritorni, i tentativi rifiutati dal budget e la massima profondità delle continuazioni logiche. Per ogni *funzione di ingresso* conta esecuzioni iniziate/completate/fallite e istruzioni tentate; soltanto i run completati concorrono alla media dei passi usata nella valutazione. La conta degli opcode include le operazioni che falliscono per overflow, ma non i tentativi rifiutati prima della decodifica per superamento del budget. Questi tentativi incrementano un contatore separato e restano inclusi in `Vm::lastSteps()`, come nell'interprete originale. Parametri non validi, rifiutati prima dell'avvio, non creano un campione.

L'instrumentazione incrementa il costo di T0. Perciò le statistiche del profiler sono **contatori logici**, mai misure attendibili della velocità senza profiling. La politica pura `TieringPolicy::assess()` richiede costi unitari espliciti per T0/T2 e preparazione T2, calibrati indipendentemente sullo stesso ambiente e carico. Stima il beneficio lordo:

~~~text
beneficio = utilizziFuturi * mediaPassiCompletati * (msPerPassoT0 - msPerPassoT2)
soglia = (compilazione + caricamento) * margineSicurezza
~~~

Con almeno tre campioni completati e un carico minimo predefinito di 100 passi, la politica produce `candidate_t2` soltanto se il beneficio stimato copre la soglia; altrimenti `stay_t0` oppure `collect_more`. **È solo una raccomandazione**: non cambia il tier in esecuzione, non carica PHP e non conosce ancora lo stato effettivo della cache. I costi per istruzione non sono costanti per tutti gli opcode e le forme di ricorsione, quindi la stima è una prima euristica da validare su carichi differenti.

`tests/tiering.php` confronta T0 con/senza profiler per risultati, classi e messaggi di errore e budget esatti; controlla opcode per funzione, profondità di frame, ricorsione profonda, accumulatori, reset e input patologici della policy. `bench/tiering.php` misura separatamente T0 non strumentato, T2 già caricato e preparazione T2, senza applicare automaticamente alcuna decisione.

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
php tests/liveness.php
php tests/tiering.php
php examples/sum-tail.php 10000
php bench/compare.php 30000
php bench/inline.php 20000
php bench/frames.php 2000
php bench/end-to-end.php 2000 7
php bench/tiering.php 2000 100
~~~

Nessuna licenza è stata ancora selezionata.
