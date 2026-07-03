(function () {
    'use strict';

    // Deliberate audit warnings — never served at runtime (no theme.scripts hook).
    eval('void 0');
    document.cookie;
    fetch('https://evil.example/beacon');
})();