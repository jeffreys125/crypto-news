/* 前台轻量交互：图片降级 + 返回顶部 */
(function () {
  'use strict';

  // 外链缩略图可能被防盗链或失效，加载失败时隐藏容器，避免出现破图
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
