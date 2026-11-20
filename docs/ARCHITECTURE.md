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
      +--> T1: basic block / fusioni     [futuro]
      +--> T2: PHP diretto + OPcache     [futuro]
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

**T1:** decodifica preparata, superistruzioni e dispatch per basic block, senza modificare i risultati osservabili o nascondere i punti di errore. Le superistruzioni devono rispettare la semantica di budget scelta.

**T2:** traduzione della MachineIR a PHP nativo, con variabili locali, cicli e chiamate dirette. Una funzione ricorsiva terminale può diventare un while. Le ricorsioni non terminali continuano a richiedere frame espliciti salvo trasformazioni giustificate semanticamente.

**Supporto funzionale:** closure, ambienti catturati, chiamate indirette specializzate, applicazione parziale e pattern matching. Queste capacità non devono dipendere da Nail.

**Dati e semantiche:** valori strutturati, interi arbitrari, float, record, rappresentazioni di stringhe/array, gestione effetti e capability dovranno essere specificati prima di ottimizzarli.

## Misurazione

Non sono stati stabiliti benchmark o speedup rispetto a PHP nativo. Misurare T0 rispetto a un interprete di riferimento e a PHP manuale semanticamente equivalente, con versione PHP e configurazione OPcache/JIT documentate, prima di fare affermazioni di prestazioni.

## Uso

~~~sh
php tests/run.php
php examples/sum-tail.php 10000
~~~

Nessuna licenza è stata ancora selezionata.
