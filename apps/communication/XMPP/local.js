/* eslint-disable no-var */
(function () {
  var wsProtocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  var host = window.location.host;
  var hostname = window.location.hostname;

  window.config = {
    name: 'Prosody Web',
    transports: {
      websocket: wsProtocol + '//' + host + '/prosody/xmpp-websocket',
    },
    hasGuestAccess: false,
    hasRegisteredAccess: true,
    anonymousHost: null,
    isTransportsUserAllowed: false,
    hasHttpAutoDiscovery: false,
    resource: 'Web XMPP',
    defaultDomain: hostname,
    defaultMuc: 'conference.' + hostname,
    isStylingDisabled: false,
    hasSendingEnterKey: false,
    connectTimeout: 5000,
    pinnedMucs: [],
    logoUrl: '',
    sso: {
      endpoint: false,
      jidHeader: 'jid',
      passwordHeader: 'password',
    },
    guestDescription: '',
  };
})();
