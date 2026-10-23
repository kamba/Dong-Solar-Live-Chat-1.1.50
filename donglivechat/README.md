Dong Solar Live Chat 1.1.0 — PrestaShop 9.0.2

Install using Modules > Module Manager. Configure under module settings. Chat inbox: Customers > Live Chat.

New: editable visitor name/email/phone/notes, optional OpenAI Responses API assistant, configuration toggles and model/prompt settings.

AI requires an OpenAI API key and paid API access. API keys are server-side. Review your privacy notice and obtain applicable consent before transmitting visitor messages to OpenAI. Test on staging first.


## v1.1.1 navigation repair
This maintenance update repairs or creates the back-office Live Chat menu tab during module upgrade and adds an Open Live Chat Inbox shortcut to module settings. It does not remove existing chat records.
After upgrading, clear the PrestaShop cache if the menu remains stale. If the menu is still absent, check employee permissions for AdminDongLiveChat.

IMPORTANT: Do not uninstall the existing module to upgrade; use the module upgrade action. Back up the database first. This package is syntax-checked, not tested against a running PrestaShop instance.

## v1.1.2
Adds a configurable left/right storefront chat button and panel position under Module Manager > Dong Solar Live Chat > Configure. Existing installations default to right. Upgrade without uninstalling to preserve chat data.

## v1.1.3
Fixes settings submission on PrestaShop 9 Symfony module configuration pages, where the route token differs from the legacy AdminModules token. Upgrade in place; do not uninstall.


1.1.4: Capture visitor IP via PrestaShop remote address, migrate IP field, server-render recent visitor fallback, show AJAX errors. IP is personal data: update privacy notice and retention controls.

Version 1.1.5: repair schema on dashboard access if module upload did not run the database migration. Test on staging; retain backups.

Version 1.1.8: Visitor details are preloaded securely into the admin dashboard so selection works even when the visitor-list AJAX refresh fails.

Version 1.1.9: Selected visitor number and IP address shown prominently above visitor details.

Version 1.1.10: visitor links support server-side selection when admin JavaScript is blocked.

### v1.1.13
Adds live active/last-hour unique visitor counts and referring website column. Referrers are recorded from browser document.referrer, with query strings excluded; earlier sessions may have unknown referrers.

## v1.1.14
Colored responsive visitor summary; consistent server-time counting; external referrer hostname and UTM/click-ID source fallback for new visitor sessions. Historical unknown sources cannot be reconstructed.

## v1.1.30
Back-office visitor list and selected visitor current-page details refresh every 3 seconds while dashboard tab is visible. Concurrent list requests are prevented. The storefront continues reporting its current page on page load and at its existing heartbeat interval.

1.1.42: Fix Smarty compilation error by moving tab JavaScript into external file; correct duplicated Settings panel.
