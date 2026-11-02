(function (Drupal) {

  Drupal.behaviors.comparenatorToolbar = {
    attach: function (context) {

      const link = context.querySelector('a[href="/comparenator/from-current"]');
      if (!link || link.dataset.comparenatorProcessed) {
        return;
      }

      link.dataset.comparenatorProcessed = "true";

      link.addEventListener('click', function (e) {
        e.preventDefault();

        // Determine current page.
        const currentPage = window.location.pathname;
        const identifier = currentPage.replace(/\//g, '_').replace(/^_+|_+$/g, '') || 'frontpage';

        const size = 'laptop';
        const url = `/comparenator/env/${identifier}/${size}/prod/local?mode=onion`;

        // Open Comparenator directly from the click event (allowed by browser).
        window.open(url, '_blank');
      });
    }
  };

})(Drupal);
