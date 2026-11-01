/**
 * File-type icons for attachments: outline ("regular") Font Awesome icons,
 * coloured by type so a PDF, a spreadsheet or a CAD drawing is recognisable at
 * a glance. Shared by the reading pane, the composer, case bubbles and the
 * details pane.
 *
 *   window.ecwFileType(filename, mime) -> {key, label, icon, color}
 *   window.ecwFileIcon(filename, mime) -> <i class="fa-regular …"> element
 */
(function () {
  'use strict';
  const TYPES = [
    { key: 'pdf',   label: 'PDF',          icon: 'fa-file-pdf',        color: '#d93025', ext: ['pdf'], mime: /pdf/ },
    { key: 'word',  label: 'Word',         icon: 'fa-file-word',       color: '#2b579a', ext: ['doc', 'docx', 'docm', 'dot', 'dotx', 'odt', 'rtf', 'pages'], mime: /word|opendocument\.text|rtf/ },
    { key: 'excel', label: 'Excel',        icon: 'fa-file-excel',      color: '#217346', ext: ['xls', 'xlsx', 'xlsm', 'xlsb', 'csv', 'ods', 'numbers'], mime: /sheet|excel|csv|opendocument\.spreadsheet/ },
    { key: 'ppt',   label: 'PowerPoint',   icon: 'fa-file-powerpoint', color: '#d24726', ext: ['ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'odp', 'key'], mime: /presentation|powerpoint/ },
    { key: 'cad',   label: 'CAD',          icon: 'fa-compass',         color: '#e67e22', ext: ['dwg', 'dxf', 'dwf', 'step', 'stp', 'iges', 'igs', 'stl', 'sldprt', 'sldasm', 'slddrw', 'ipt', 'iam', 'idw', 'x_t', 'x_b', 'catpart', 'catproduct', 'prt', 'asm', '3dm', 'f3d', 'rvt', 'rfa', 'ifc', 'obj', '3mf', 'jt', 'sat', 'par', 'psm'], mime: /dwg|dxf|step|iges|stl|acad|autocad|vnd\.ms-pki\.stl/ },
    { key: 'image', label: 'Image',        icon: 'fa-file-image',      color: '#8e44ad', ext: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'heic', 'heif', 'tif', 'tiff', 'ico'], mime: /^image\// },
    { key: 'zip',   label: 'Archive',      icon: 'fa-file-zipper',     color: '#b7791f', ext: ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'], mime: /zip|compressed|x-rar|x-7z|x-tar|gzip/ },
    { key: 'video', label: 'Video',        icon: 'fa-file-video',      color: '#c2185b', ext: ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'm4v'], mime: /^video\// },
    { key: 'audio', label: 'Audio',        icon: 'fa-file-audio',      color: '#0f9d9a', ext: ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'wma'], mime: /^audio\// },
    { key: 'email', label: 'Email',        icon: 'fa-envelope',        color: '#1a73e8', ext: ['eml', 'msg', 'oft'], mime: /message\/rfc822|ms-outlook/ },
    { key: 'cal',   label: 'Calendar',     icon: 'fa-calendar',        color: '#e8710a', ext: ['ics', 'vcs'], mime: /calendar/ },
    { key: 'code',  label: 'Code',         icon: 'fa-file-code',       color: '#3b82f6', ext: ['json', 'xml', 'html', 'htm', 'js', 'ts', 'css', 'php', 'py', 'sql', 'sh', 'yml', 'yaml', 'java', 'c', 'cpp', 'cs'], mime: /json|xml|javascript|x-php|x-python|sql|html/ },
    { key: 'text',  label: 'Text',         icon: 'fa-file-lines',      color: '#5f6b7a', ext: ['txt', 'md', 'log', 'ini', 'cfg'], mime: /^text\// },
  ];
  const FALLBACK = { key: 'file', label: 'File', icon: 'fa-file', color: '#6b7280' };

  window.ecwFileType = function (filename, mime) {
    const name = String(filename || '').toLowerCase();
    const ext = name.includes('.') ? name.split('.').pop() : '';
    const type = String(mime || '').toLowerCase();
    // The extension decides first (mail clients often send application/octet-stream).
    return TYPES.find((t) => ext && t.ext.includes(ext))
      || TYPES.find((t) => type && type !== 'application/octet-stream' && t.mime.test(type))
      || FALLBACK;
  };

  window.ecwFileIcon = function (filename, mime) {
    const t = window.ecwFileType(filename, mime);
    const i = document.createElement('i');
    i.className = 'fa-regular ' + t.icon + ' file-icon file-icon-' + t.key;
    i.style.color = t.color;
    i.setAttribute('aria-hidden', 'true');
    i.title = t.label;
    return i;
  };
})();
