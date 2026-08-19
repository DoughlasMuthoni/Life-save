/**
 * Registers the service worker and surfaces a minimal "update available"
 * banner when a new version has been deployed (CLAUDE.md §11: clear update
 * strategy). The new worker installs in the background and waits; we only
 * tell it to take over once the user acts on the prompt, so an in-progress
 * session is never yanked out from under someone mid-task.
 */
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((registration) => {
            registration.addEventListener('updatefound', () => {
                const installing = registration.installing;

                if (!installing) {
                    return;
                }

                installing.addEventListener('statechange', () => {
                    const hasExistingController = Boolean(navigator.serviceWorker.controller);

                    if (installing.state === 'installed' && hasExistingController) {
                        showUpdateBanner(() => {
                            installing.postMessage('SKIP_WAITING');
                        });
                    }
                });
            });
        }).catch(() => {
            // Registration failure shouldn't break the app — it just means
            // no offline shell / installability this session.
        });

        let reloaded = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (reloaded) return;
            reloaded = true;
            window.location.reload();
        });
    });
}

function showUpdateBanner(onRefresh) {
    if (document.getElementById('lifeos-update-banner')) {
        return;
    }

    const banner = document.createElement('div');
    banner.id = 'lifeos-update-banner';
    banner.setAttribute('role', 'status');
    banner.style.cssText = [
        'position:fixed', 'left:1rem', 'right:1rem', 'bottom:1rem', 'z-index:9999',
        'display:flex', 'align-items:center', 'justify-content:space-between', 'gap:0.75rem',
        'background:#0f172a', 'color:#fff', 'padding:0.75rem 1rem', 'border-radius:0.75rem',
        'box-shadow:0 10px 25px rgba(0,0,0,0.25)', 'font:500 0.875rem system-ui,sans-serif',
        'max-width:26rem', 'margin:0 auto',
    ].join(';');

    banner.innerHTML = '<span>A new version is available.</span>';

    const button = document.createElement('button');
    button.textContent = 'Refresh';
    button.style.cssText = [
        'font:inherit', 'color:#0f172a', 'background:#fff', 'border:none',
        'border-radius:0.5rem', 'padding:0.4rem 0.8rem', 'cursor:pointer', 'flex-shrink:0',
    ].join(';');
    button.addEventListener('click', () => {
        onRefresh();
        banner.remove();
    });

    banner.appendChild(button);
    document.body.appendChild(banner);
}
