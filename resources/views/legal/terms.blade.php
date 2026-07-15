<x-legal.page
    title="Termini essenziali di utilizzo"
    description="Regole operative minime per utilizzare Product Vault durante la fase MVP."
>
    <div data-testid="legal-terms" class="space-y-8">
        <section>
            <h2>Natura del servizio</h2>
            <p>
                Product Vault organizza documenti, informazioni prodotto, coperture e
                pratiche successive all’acquisto. Il servizio supporta l’utente nella
                gestione dell’archivio ma non sostituisce verifiche presso venditori,
                produttori, assicurazioni, professionisti o autorità competenti.
            </p>
        </section>

        <section>
            <h2>Account e workspace</h2>
            <p>
                L’utente deve mantenere sicure le proprie credenziali e verificare chi può
                accedere al workspace. Gli inviti devono essere inviati soltanto a persone
                autorizzate a vedere documenti, prodotti e pratiche condivise.
            </p>
        </section>

        <section>
            <h2>Contenuti caricati</h2>
            <p>
                L’utente dichiara di avere il diritto di caricare e utilizzare i documenti
                inseriti. Non devono essere caricati contenuti illeciti, estranei al servizio,
                dannosi o contenenti dati di terzi non necessari.
            </p>
        </section>

        <section>
            <h2>Dati estratti e stime</h2>
            <p>
                OCR, parser, regole di riconoscimento e suggerimenti possono produrre errori.
                Date, importi, coperture, scadenze, brand, categorie e modelli devono essere
                verificati dall’utente prima di essere utilizzati per decisioni operative.
            </p>
            <p>
                Una copertura indicata come stimata non costituisce conferma giuridica della
                garanzia o del diritto a rimborso, riparazione o sostituzione.
            </p>
        </section>

        <section>
            <h2>Pratiche e comunicazioni</h2>
            <p>
                Le bozze generate dal servizio devono essere controllate prima dell’invio.
                Product Vault registra stato, documenti collegati e risultato della pratica,
                ma non garantisce l’accettazione della richiesta da parte del destinatario.
            </p>
        </section>

        <section>
            <h2>Piani e limiti durante l’MVP</h2>
            <p>
                Il piano Free e gli altri piani presenti nel catalogo sono in validazione.
                In modalità monitoraggio i limiti vengono misurati e mostrati senza addebiti,
                upgrade automatici o blocchi aggressivi. Prezzi e condizioni commerciali
                potranno essere definiti successivamente con comunicazione separata.
            </p>
        </section>

        <section>
            <h2>Disponibilità e modifiche</h2>
            <p>
                Il servizio può essere aggiornato, sospeso per manutenzione o modificato per
                motivi tecnici e di sicurezza. Durante la fase pilota alcune funzionalità
                possono cambiare e non è garantita una disponibilità continua.
            </p>
        </section>

        <section>
            <h2>Responsabilità dell’utente</h2>
            <p>
                L’utente resta responsabile della verifica dei dati, delle comunicazioni
                inviate, della gestione dei membri e della conservazione di eventuali copie
                necessarie per obblighi personali o professionali.
            </p>
        </section>

        <section>
            <h2>Contatti e revisione</h2>
            <p>
                Per segnalazioni scrivi a
                <a href="mailto:{{ config('release_readiness.legal.support_email') }}">
                    {{ config('release_readiness.legal.support_email') }}
                </a>.
                Questi termini descrivono l’MVP e devono essere sottoposti a revisione prima
                della distribuzione pubblica o commerciale.
            </p>
        </section>
    </div>
</x-legal.page>
