const menuToggle = document.querySelector('.menu-toggle');
const navigation = document.getElementById('site-navigation');
const mobileViewport = window.matchMedia('(max-width: 1050px)');

function closeMenu() {
    menuToggle.setAttribute('aria-expanded', 'false');
    navigation.classList.remove('is-open');
}

menuToggle.addEventListener('click', () => {
    const expanded = menuToggle.getAttribute('aria-expanded') === 'true';
    menuToggle.setAttribute('aria-expanded', String(!expanded));
    navigation.classList.toggle('is-open', !expanded);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
        closeMenu();
        menuToggle.focus();
    }
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('.nav')) closeMenu();
});

mobileViewport.addEventListener('change', closeMenu);

const campaignPlaceholder = new URL('../images/campaign-placeholder.svg', document.currentScript.src).href;
document.querySelectorAll('.campaign-image img').forEach((image) => {
    const usePlaceholder = () => {
        image.src = campaignPlaceholder;
    };
    image.addEventListener('error', usePlaceholder, { once: true });
    if (image.complete && image.naturalWidth === 0) usePlaceholder();
});
