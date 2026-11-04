(function (Drupal, once) {

  Drupal.behaviors.comparenatorViewer = {
    attach: function (context) {

      once('comparenator-slider', '#comparanator-slider', context).forEach((slider) => {
        const topLayer = slider.closest('.comparanator-container').querySelector('#comparanator-top');
        slider.addEventListener('input', function () {
          topLayer.style.opacity = this.value / 100;
        });
      });

      // Optional: Side‑by‑side scroll sync.
      once('comparenator-scroll', '.comparanator-side-by-side', context).forEach((container) => {
        const left = container.querySelector('.comparanator-side.left');
        const right = container.querySelector('.comparanator-side.right');
        let syncing = false;

        function syncScroll(source, target) {
          if (syncing) return;
          syncing = true;
          target.scrollTop = source.scrollTop;
          target.scrollLeft = source.scrollLeft;
          syncing = false;
        }

        left.addEventListener('scroll', () => syncScroll(left, right));
        right.addEventListener('scroll', () => syncScroll(right, left));
      });

    }
  };

})(Drupal, once);

