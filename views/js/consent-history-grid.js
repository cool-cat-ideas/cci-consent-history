$(() => {
  const grid = new window.prestashop.component.Grid('cci_consent_acceptances');

  grid.addExtension(new window.prestashop.component.GridExtensions.SortingExtension());
  grid.addExtension(new window.prestashop.component.GridExtensions.FiltersResetExtension());

  const modal = $('#cci-consent-history-wording-modal');
  const content = modal.find('[data-cci-consent-history-wording-content]');

  $(document).on('click', '.cci-consent-history-show-wording', (event) => {
    const trigger = event.currentTarget;
    const encodedWording = trigger.getAttribute('data-wording') || '';
    let wording = '';

    try {
      const bytes = Uint8Array.from(window.atob(encodedWording), (character) =>
        character.charCodeAt(0),
      );
      wording = new TextDecoder('utf-8').decode(bytes);
    } catch (error) {
      wording = '';
    }

    content.text(wording);
    modal.modal('show');
  });

  modal.on('hidden.bs.modal', () => {
    content.text('');
  });
});
