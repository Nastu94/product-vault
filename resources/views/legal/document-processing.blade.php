<x-legal.page
    title="Come vengono trattati i documenti"
    description="Cosa accade dal caricamento alla revisione, alla scheda prodotto e alle pratiche."
>
    <div data-testid="legal-document-processing" class="space-y-8">
        <section>
            <h2>1. Caricamento</h2>
            <p>
                Il file originale viene associato al workspace e conservato nello storage
                configurato per i documenti privati. L’accesso avviene tramite autorizzazioni
                applicative; il file non deve essere esposto come risorsa pubblica diretta.
            </p>
        </section>

        <section>
            <h2>2. Estrazione</h2>
            <p>
                Il sistema prova a leggere il testo digitale del PDF. Quando necessario può
                convertire pagine in immagini e utilizzare OCR. Il risultato può contenere
                errori dovuti a qualità, layout, lingua, scansione o limiti degli strumenti.
            </p>
        </section>

        <section>
            <h2>3. Classificazione e righe</h2>
            <p>
                Product Vault tenta di distinguere documenti pertinenti e non pertinenti,
                riconoscere righe prodotto, quantità e importi e individuare possibili
                informazioni utili alla scheda prodotto.
            </p>
        </section>

        <section>
            <h2>4. Documento e prodotto restano distinti</h2>
            <p>
                Un documento non diventa automaticamente un prodotto. I candidati generati
                restano collegati alla fonte e devono poter essere confermati, modificati o
                rifiutati. Il file originale resta la prova primaria del dato estratto.
            </p>
        </section>

        <section>
            <h2>5. Revisione manuale</h2>
            <p>
                I segnali tecnici, le informazioni mancanti e le stime vengono presentati
                all’utente. Solo la revisione consente di trasformare un suggerimento in un
                dato confermato della scheda prodotto.
            </p>
        </section>

        <section>
            <h2>6. Coperture e scadenze</h2>
            <p>
                Le coperture possono essere calcolate a partire dalla data di acquisto e da
                regole configurate. Quando non provengono da una fonte verificata vengono
                indicate come stime da controllare.
            </p>
        </section>

        <section>
            <h2>7. Pratiche successive all’acquisto</h2>
            <p>
                Una pratica può collegare prodotto, documenti, descrizione del problema,
                comunicazioni e risultato. Le bozze restano modificabili e l’invio verso
                soggetti esterni non deve essere considerato automatico se non esplicitamente
                implementato e confermato dall’utente.
            </p>
        </section>

        <section>
            <h2>8. Errori e ripetizioni</h2>
            <p>
                Le elaborazioni possono essere ripetute in caso di errore. Eventi di utilizzo
                e operazioni sensibili devono usare chiavi idempotenti o transazioni per
                evitare duplicazioni. Gli errori tecnici vengono registrati per diagnosi.
            </p>
        </section>

        <section>
            <h2>9. Cancellazione</h2>
            <p>
                La rimozione di un documento o di un account deve considerare file fisici,
                record collegati, audit, backup e obblighi applicabili. Prima del rilascio
                pubblico il gestore deve definire una procedura operativa verificata.
            </p>
        </section>

        <section>
            <h2>10. Dati da non caricare</h2>
            <p>
                Carica soltanto ciò che serve alla gestione del prodotto. Oscura o evita dati
                sanitari, finanziari, credenziali, documenti di identità e informazioni di
                terzi non necessarie al flusso.
            </p>
        </section>
    </div>
</x-legal.page>
