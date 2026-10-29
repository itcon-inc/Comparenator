(function (Drupal) {
  Drupal.behaviors.comparenator = {
    attach: function (context) {
      const slider = context.querySelector('#comparanator-slider');
      const top = context.querySelector('#comparanator-top');

      if (slider && top) {
        slider.addEventListener('input', e => {
          top.style.opacity = e.target.value / 100;
        });
      }
    }
  };
})(Drupal);
