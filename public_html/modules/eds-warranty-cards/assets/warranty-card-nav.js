(function () {
    'use strict';

    function placeRegistryLink() {
        var registryLink = document.getElementById('eds-warranty-cards-nav-link');
        if (!registryLink) {
            return;
        }

        var workOrdersUrl = registryLink.dataset.workOrdersUrl;
        var container = registryLink.parentElement;
        if (!workOrdersUrl || !container) {
            return;
        }

        var workOrdersLink = null;
        Array.from(container.querySelectorAll('a[href]')).some(function (candidate) {
            if (candidate.getAttribute('href') === workOrdersUrl) {
                workOrdersLink = candidate;
                return true;
            }
            return false;
        });

        if (!workOrdersLink || workOrdersLink.parentElement !== container || workOrdersLink === registryLink) {
            return;
        }

        workOrdersLink.insertAdjacentElement('afterend', registryLink);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', placeRegistryLink, { once: true });
    } else {
        placeRegistryLink();
    }
}());
