// Runs inside the DDEV web container, not on the host: that is where Node and
// Chromium are installed (see docs/setup.md). The web server it drives is a
// throwaway PHP built-in server bound to 127.0.0.1 inside the same container,
// not the public https://poe-build-helper.ddev.site — that URL serves the
// development database and a self-signed certificate, neither of which this
// test wants.
export default {
    testDir: 'tests/e2e',
    timeout: 30000,
    use: {
        baseURL: 'http://127.0.0.1:8001',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'APP_ENV=test php -S 127.0.0.1:8001 -t public',
        url: 'http://127.0.0.1:8001/',
        reuseExistingServer: !process.env.CI,
    },
};
