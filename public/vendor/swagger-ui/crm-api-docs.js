/*
 * CRM: Swagger UI bootstrap for the 1C integration API docs (no inline script, CSP-safe).
 * Read-only: "Try it out" is disabled (no request is ever sent from this page, no token entered).
 */
(function () {
    var root = document.getElementById('swagger-ui');
    if (!root || typeof window.SwaggerUIBundle !== 'function') {
        return;
    }
    window.SwaggerUIBundle({
        url: root.getAttribute('data-spec-url'),
        domNode: root,
        deepLinking: true,
        supportedSubmitMethods: [],
        tryItOutEnabled: false,
        validatorUrl: null,
        docExpansion: 'list',
        defaultModelsExpandDepth: 0,
        displayRequestDuration: false,
        persistAuthorization: false
    });
})();
