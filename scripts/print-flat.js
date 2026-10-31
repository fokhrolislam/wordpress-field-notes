// Run in the page just before Chrome prints it to PDF.
// Soft shadows, gradients and filters print as shading that some PDF viewers draw as grey or black boxes.
// Shadows become a thin outline (no layout shift), gradients become their first colour.
(function () {
  document.querySelectorAll('*').forEach(function (el) {
    var cs = getComputedStyle(el);
    if (cs.boxShadow !== 'none') {
      el.style.boxShadow = 'none';
      el.style.outline = '1px solid rgba(0,0,0,.12)';
      el.style.outlineOffset = '-1px';
    }
    if (cs.backgroundImage.indexOf('gradient') > -1) {
      var first = cs.backgroundImage.match(/rgba?\([^)]+\)/);
      el.style.backgroundImage = 'none';
      if (first) el.style.backgroundColor = first[0];
    }
    if (cs.textShadow !== 'none') el.style.textShadow = 'none';
    if (cs.filter !== 'none') el.style.filter = 'none';
    if (cs.backdropFilter && cs.backdropFilter !== 'none') el.style.backdropFilter = 'none';
  });
})();
