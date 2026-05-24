//
/*
|--------------------------------------------------------------------------
| Product Vault - Mobile welcome menu
|--------------------------------------------------------------------------
|
| Chiude il menu mobile della welcome page dopo il click su un link interno
| e permette la chiusura con il tasto Escape.
|
*/

document.addEventListener('DOMContentLoaded', () => {
    const mobileMenu = document.getElementById('welcome-mobile-menu');

    if (!mobileMenu) {
        return;
    }

    mobileMenu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            mobileMenu.removeAttribute('open');
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            mobileMenu.removeAttribute('open');
        }
    });
});