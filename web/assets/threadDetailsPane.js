/* Shared thread-details computation + rendering for the right-hand details
 * pane — used by both the reading pane (app.js, one open thread) and the
 * case timeline (case.js, the thread with focus among several side by side).
 *
 * Kept in its own file, independent of either page's state shape: the
 * caller normalizes whatever it already has in memory (ecw_thread_detail()'s
 * or ecw_case_timeline()'s payload) into the small shape below, so this file
 * never has to know which page it is running on.
 *
 *   input = {
 *     subject: string,
 *     caseInfo: {number, name} | null,
 *     mailboxLabel: string | null,
 *     messages: [{
 *       id, from: {name, email}, to: [{name,email}], cc: [{name,email}],
 *       occurred_at: 'YYYY-MM-DD HH:MM:SS' (UTC, as the API returns it),
 *       attachments: [{id, filename, mime_type, size}],
 *       body_html: string,   // already sanitized server-side (HTMLPurifier)
 *     }],
 *   }
 *
 * XSS POSTURE: rendering never uses innerHTML. Every mail-derived string
 * (names, subjects, filenames, urls) is written with textContent or as a
 * href/title attribute value, never parsed as markup.
 */
(function () {
  'use strict';

  function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  }

  // Cheap, regex-only link extraction: href="..."/href='...' attributes plus
  // any bare http(s) URL in the markup. No DOMParser dependency, so this runs
  // the same way in a browser and in the Node test harness.
  var URL_RE = /href\s*=\s*"([^"]*)"|href\s*=\s*'([^']*)'|(https?:\/\/[^\s"'<>]+)/gi;

  function extractLinks(html) {
    if (!html) return [];
    var found = [];
    var match;
    URL_RE.lastIndex = 0;
    while ((match = URL_RE.exec(html))) {
      var candidate = match[1] || match[2] || match[3];
      if (candidate) found.push(candidate);
    }
    return found;
  }

  function domainOf(url) {
    try {
      return new URL(url).hostname.replace(/^www\./, '');
    } catch (e) {
      return null;
    }
  }

  function fileIcon(mimeType, filename) {
    var type = String(mimeType || '').toLowerCase();
    var name = String(filename || '').toLowerCase();
    if (type.indexOf('pdf') !== -1 || name.endsWith('.pdf')) return 'fa-file-pdf';
    if (type.indexOf('image/') === 0) return 'fa-file-image';
    if (type.indexOf('zip') !== -1 || type.indexOf('compressed') !== -1 || /\.(zip|rar|7z|tar|gz)$/.test(name)) return 'fa-file-zipper';
    if (type.indexOf('sheet') !== -1 || /\.(xlsx?|csv)$/.test(name)) return 'fa-file-excel';
    if (type.indexOf('word') !== -1 || /\.docx?$/.test(name)) return 'fa-file-word';
    if (type.indexOf('presentation') !== -1 || /\.pptx?$/.test(name)) return 'fa-file-powerpoint';
    return 'fa-paperclip';
  }

  /** @return {overview, participants, files, links} */
  function ecwComputeThreadDetails(input) {
    var messages = input.messages || [];

    var first = null;
    var last = null;
    var participantMap = new Map();
    var files = [];
    var linkMap = new Map(); // url -> domain

    function bump(person, role, when) {
      if (!person || !person.email) return;
      var key = person.email.toLowerCase();
      var entry = participantMap.get(key);
      if (!entry) {
        entry = { email: person.email, name: person.name || null, roles: new Set(), messageCount: 0, lastDate: null };
        participantMap.set(key, entry);
      }
      if (person.name && !entry.name) entry.name = person.name;
      entry.roles.add(role);
      entry.messageCount++;
      if (when && (!entry.lastDate || when > entry.lastDate)) entry.lastDate = when;
    }

    messages.forEach(function (message) {
      var when = message.occurred_at || null;
      if (when) {
        if (first === null || when < first) first = when;
        if (last === null || when > last) last = when;
      }

      bump(message.from, 'sender', when);
      (message.to || []).forEach(function (p) { bump(p, 'to', when); });
      (message.cc || []).forEach(function (p) { bump(p, 'cc', when); });

      (message.attachments || []).forEach(function (attachment) {
        files.push({
          id: attachment.id,
          filename: attachment.filename || 'attachment',
          mimeType: attachment.mime_type || '',
          size: attachment.size || 0,
          sender: message.from || null,
          date: when,
        });
      });

      extractLinks(message.body_html || '').forEach(function (url) {
        if (!/^https?:\/\//i.test(url) || linkMap.has(url)) return;
        var domain = domainOf(url);
        if (domain) linkMap.set(url, domain);
      });
    });

    var participants = Array.from(participantMap.values())
      .map(function (entry) {
        return {
          email: entry.email, name: entry.name, roles: Array.from(entry.roles),
          messageCount: entry.messageCount, lastDate: entry.lastDate,
        };
      })
      .sort(function (a, b) {
        return b.messageCount - a.messageCount
          || String(a.name || a.email).localeCompare(String(b.name || b.email));
      });

    files.sort(function (a, b) { return String(b.date || '').localeCompare(String(a.date || '')); });

    var links = Array.from(linkMap.entries()).map(function (pair) { return { url: pair[0], domain: pair[1] }; });

    var caseName = null;
    if (input.caseInfo) {
      caseName = input.caseInfo.number ? (input.caseInfo.number + ' · ' + input.caseInfo.name) : input.caseInfo.name;
    }

    return {
      overview: {
        subject: input.subject || '(no subject)',
        caseName: caseName,
        firstDate: first,
        lastDate: last,
        messageCount: messages.length,
        mailbox: input.mailboxLabel || null,
      },
      participants: participants,
      files: files,
      links: links,
    };
  }

  // ------------------------------------------------------------ rendering

  function sectionOpen(key) {
    try {
      var stored = localStorage.getItem('ot.pane.details.section.' + key);
      return stored === null ? true : stored === '1';
    } catch (e) { return true; }
  }

  function persistSectionOpen(key, open) {
    try { localStorage.setItem('ot.pane.details.section.' + key, open ? '1' : '0'); } catch (e) {}
  }

  function section(key, title) {
    var el = document.createElement('details');
    el.className = 'details-section';
    el.open = sectionOpen(key);
    el.addEventListener('toggle', function () { persistSectionOpen(key, el.open); });
    var summary = document.createElement('summary');
    summary.textContent = title;
    el.appendChild(summary);
    var body = document.createElement('div');
    body.className = 'details-section-body';
    el.appendChild(body);
    return { el: el, body: body };
  }

  function emptyState(container, text) {
    var p = document.createElement('p');
    p.className = 'muted details-empty';
    p.textContent = text;
    container.appendChild(p);
  }

  function fact(container, label, value) {
    var dt = document.createElement('dt');
    dt.textContent = label;
    var dd = document.createElement('dd');
    dd.textContent = value || '—';
    container.append(dt, dd);
  }

  function roleLabel(role) {
    return role === 'sender' ? 'From' : role === 'to' ? 'To' : 'Cc';
  }

  /**
   * @param container the pane's content mount point (cleared and rebuilt each call)
   * @param details   the object ecwComputeThreadDetails() returned
   * @param opts      { formatDate(iso): string, formatFull(iso): string,
   *                    onParticipantClick(participant), attachmentHref(id): string }
   */
  function ecwRenderThreadDetailsPane(container, details, opts) {
    opts = opts || {};
    var formatFull = opts.formatFull || function (v) { return v || '—'; };
    var attachmentHref = opts.attachmentHref || function (id) { return 'api/index.php?action=attachment&id=' + id; };

    container.textContent = '';

    // ---- overview -----------------------------------------------------
    var overview = section('overview', 'Overview');
    var dl = document.createElement('dl');
    dl.className = 'details-fact-list';
    fact(dl, 'Subject', details.overview.subject);
    if (details.overview.caseName) fact(dl, 'Case', details.overview.caseName);
    fact(dl, 'Mailbox', details.overview.mailbox);
    fact(dl, 'Messages', String(details.overview.messageCount));
    fact(dl, 'First message', details.overview.firstDate ? formatFull(details.overview.firstDate) : '—');
    fact(dl, 'Last message', details.overview.lastDate ? formatFull(details.overview.lastDate) : '—');
    overview.body.appendChild(dl);
    container.appendChild(overview.el);

    // ---- participants ---------------------------------------------------
    var participantsSection = section('participants', 'Participants (' + details.participants.length + ')');
    if (!details.participants.length) {
      emptyState(participantsSection.body, 'No participants found.');
    } else {
      var list = document.createElement('ul');
      list.className = 'details-participant-list';
      details.participants.forEach(function (participant) {
        var li = document.createElement('li');
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'details-participant';

        var name = document.createElement('span');
        name.className = 'details-participant-name';
        name.textContent = participant.name || participant.email;

        var email = document.createElement('span');
        email.className = 'details-participant-email';
        email.textContent = participant.name ? participant.email : '';

        var roles = document.createElement('span');
        roles.className = 'details-role-badges';
        participant.roles.forEach(function (role) {
          var badge = document.createElement('span');
          badge.className = 'role-badge role-' + role;
          badge.textContent = roleLabel(role);
          roles.appendChild(badge);
        });

        var meta = document.createElement('span');
        meta.className = 'details-participant-meta muted';
        meta.textContent = participant.messageCount + (participant.messageCount === 1 ? ' message' : ' messages')
          + (participant.lastDate ? ' · last ' + formatFull(participant.lastDate) : '');

        button.append(name, email, roles, meta);
        if (opts.onParticipantClick) {
          button.addEventListener('click', function () { opts.onParticipantClick(participant); });
        }
        li.appendChild(button);
        // Their contact card: every case and conversation with them (contacts.php).
        if (participant.email && window.ecwWebBase !== undefined) {
          var card = document.createElement('a');
          card.className = 'details-contact-link';
          card.href = (window.ecwWebBase || '') + 'contacts.php?email=' + encodeURIComponent(participant.email);
          card.title = 'Contact card: all cases and conversations with ' + (participant.name || participant.email);
          card.setAttribute('aria-label', card.title);
          var ci = document.createElement('i');
          ci.className = 'fa fa-address-card';
          ci.setAttribute('aria-hidden', 'true');
          card.appendChild(ci);
          li.appendChild(card);
        }
        list.appendChild(li);
      });
      participantsSection.body.appendChild(list);
    }
    container.appendChild(participantsSection.el);

    // ---- files shared -----------------------------------------------------
    var filesSection = section('files', 'Files shared (' + details.files.length + ')');
    if (!details.files.length) {
      emptyState(filesSection.body, 'No files in this thread.');
    } else {
      var fileList = document.createElement('ul');
      fileList.className = 'details-file-list';
      details.files.forEach(function (file) {
        var li = document.createElement('li');
        var link = document.createElement('a');
        link.className = 'details-file';
        link.href = attachmentHref(file.id);
        link.dataset.name = file.filename || 'attachment';
        link.dataset.mime = file.mimeType || '';
        link.dataset.size = String(file.size || 0);

        // Shared, coloured file-type icon (file-icons.js); the old mapping is the fallback.
        var icon = window.ecwFileIcon ? window.ecwFileIcon(file.filename, file.mimeType) : document.createElement('span');
        if (!window.ecwFileIcon) icon.className = 'fa ' + fileIcon(file.mimeType, file.filename);
        icon.classList.add('details-file-icon');
        icon.setAttribute('aria-hidden', 'true');

        var info = document.createElement('span');
        info.className = 'details-file-info';
        var name = document.createElement('span');
        name.className = 'details-file-name';
        name.textContent = file.filename;
        var meta = document.createElement('span');
        meta.className = 'details-file-meta muted';
        var sender = file.sender ? (file.sender.name || file.sender.email) : '';
        meta.textContent = [formatSize(file.size), sender, file.date ? formatFull(file.date) : '']
          .filter(Boolean).join(' · ');
        info.append(name, meta);

        link.append(icon, info);
        li.appendChild(link);
        fileList.appendChild(li);
      });
      filesSection.body.appendChild(fileList);
    }
    container.appendChild(filesSection.el);

    // ---- links -----------------------------------------------------
    var linksSection = section('links', 'Links (' + details.links.length + ')');
    if (!details.links.length) {
      emptyState(linksSection.body, 'No links found in this thread.');
    } else {
      var linkList = document.createElement('ul');
      linkList.className = 'details-link-list';
      details.links.forEach(function (item) {
        var li = document.createElement('li');
        var link = document.createElement('a');
        link.className = 'details-link';
        link.href = item.url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.title = item.url;
        link.textContent = item.domain;
        li.appendChild(link);
        linkList.appendChild(li);
      });
      linksSection.body.appendChild(linkList);
    }
    container.appendChild(linksSection.el);
  }

  window.ecwComputeThreadDetails = ecwComputeThreadDetails;
  window.ecwRenderThreadDetailsPane = ecwRenderThreadDetailsPane;
})();
