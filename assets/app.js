/* Front-end interactions: broken-image fallback + back-to-top */
(function () {
  'use strict';

  // Remote images may be hotlink-protected or expire; hide them instead of showing a broken icon
  function hideBrokenImages() {
    var imgs = document.querySelectorAll('img');
    for (var i = 0; i < imgs.length; i++) {
      imgs[i].addEventListener('error', function () {
        var box = this.parentNode;
        if (box && box.className === 'card-thumb') {
          box.style.display = 'none';
        } else {
          this.style.display = 'none';
        }
      });
    }
  }

  function initBackTop() {
    var btn = document.getElementById('backTop');
    if (!btn) return;
    window.addEventListener('scroll', function () {
      btn.style.display = (window.pageYOffset || document.documentElement.scrollTop) > 400 ? 'block' : 'none';
    });
    btn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      hideBrokenImages();
      initBackTop();
    });
  } else {
    hideBrokenImages();
    initBackTop();
  }
})();
