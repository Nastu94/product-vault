<x-legal.page
    title="Informativa privacy"
    description="Come Product Vault tratta account, workspace, documenti e dati operativi durante la fase MVP."
>
    <div data-testid="legal-privacy" class="space-y-8">
        <section>
            <h2>Titolare e contatti</h2>
            <p>
                Il soggetto che gestisce questa installazione di Product Vault è
                {{ config('release_readiness.legal.controller_name') }}.
                Per richieste relative a privacy, accesso o cancellazione puoi scrivere a
                <a href="mailto:{{ config('release_readiness.legal.support_email') }}">
                    {{ config('release_readiness.legal.support_email') }}
                </a>.
            </p>
        </section>

        <section>
            <h2>Dati trattati</h2>
            <p>Il servizio può trattare:</p>
            <ul>
                <li>dati dell’account e del workspace;</li>
                <li>documenti caricati, immagini e relativi metadati;</li>
                <li>testo estratto tramite parser PDF o OCR;</li>
                <li>schede prodotto, coperture, pratiche e note inserite dall’utente;</li>
                <li>eventi tecnici, log di sicurezza, utilizzo del piano e contatori operativi.</li>
            </ul>
        </section>

        <section>
            <h2>Finalità</h2>
            <p>
                I dati vengono utilizzati per fornire l’archivio personale o condiviso,
                elaborare i documenti, permettere la revisione dei risultati, gestire
                prodotti e pratiche, proteggere gli accessi e monitorare il corretto
                funzionamento del servizio.
            </p>
        </section>

        <section>
            <h2>Documenti e contenuti dell’utente</h2>
            <p>
                I file caricati restano associati al workspace che li ha inseriti. Product
                Vault non considera automaticamente corretto ogni dato estratto: il testo
                originale, i suggerimenti e le conferme dell’utente restano distinti.
            </p>
            <p>
                Evita di caricare documenti non necessari o contenenti dati particolarmente
                delicati che non servono alla gestione del prodotto o della pratica.
            </p>
        </section>

        <section>
            <h2>Conservazione e cancellazione</h2>
            <p>
                I dati vengono conservati per il tempo necessario a erogare il servizio,
                rispettare obblighi applicabili, gestire sicurezza e backup e consentire
                all’utente di utilizzare il proprio archivio. Tempi definitivi e procedure
                operative devono essere configurati dal gestore prima del rilascio pubblico.
            </p>
        </section>

        <section>
            <h2>Accesso e condivisione</h2>
            <p>
                L’accesso è limitato agli utenti autorizzati del workspace e ai fornitori
                tecnici indispensabili all’erogazione del servizio. Il proprietario del
                workspace è responsabile degli inviti e della rimozione dei membri.
            </p>
        </section>

        <section>
            <h2>Diritti e richieste</h2>
            <p>
                Puoi chiedere informazioni sui dati associati al tuo account, la rettifica,
                l’esportazione o la cancellazione quando applicabile. Le richieste vengono
                valutate considerando identità del richiedente, sicurezza del workspace,
                backup e obblighi di conservazione.
            </p>
        </section>

        <section>
            <h2>Stato del documento</h2>
            <p>
                Questa informativa descrive l’MVP e deve essere verificata e adattata dal
                gestore del servizio prima di una distribuzione pubblica o commerciale.
            </p>
        </section>
    </div>
</x-legal.page>
