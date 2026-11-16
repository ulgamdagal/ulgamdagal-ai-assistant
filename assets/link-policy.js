(() => {
  'use strict';
  window.UlgamdagalLinkAllowed = (href, hosts) => {
    try {
      const url = new URL(href);
      if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || url.port) return false;
      const host = url.hostname.toLowerCase().replace(/\.$/, '');
      return Array.isArray(hosts) && hosts.includes(host);
    } catch (_) { return false; }
  };
})();
