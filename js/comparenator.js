(function (Drupal) {

  Drupal.behaviors.comparenatorViewer = {
    attach: function (context) {

      // Onion‑skin slider.
      const slider = context.querySelector('#comparanator-slider');
      const topLayer = context.querySelector('#comparanator-top');

      if (slider && topLayer) {
        slider.addEventListener('input', function () {
          topLayer.style.opacity = this.value / 100;
        });
      }

      // Optional: Side‑by‑side scroll sync.
      const left = context.querySelector('.comparanator-side.left');
      const right = context.querySelector('.comparanator-side.right');

      if (left && right) {
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
      }

    }
  };

})(Drupal);
