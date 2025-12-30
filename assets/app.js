/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import 'bulma/css/bulma.min.css';
import './styles/app.css';
import htmx from 'htmx.org';

window.htmx = htmx;

htmx.onLoad((content) => {
    const $navbarBurgers = content.querySelectorAll('.navbar-burger');

    $navbarBurgers.forEach(el => {
        el.addEventListener('click', () => {
            const target = el.dataset.target;
            const $target = document.getElementById(target);

            if ($target) {
                el.classList.toggle('is-active');
                $target.classList.toggle('is-active');

                const expanded = el.getAttribute('aria-expanded') === 'true';
                el.setAttribute('aria-expanded', !expanded);

                // Lock/unlock body scroll
                document.documentElement.classList.toggle('is-clipped-mobile');
            }
        });
    });
});
