const initializeMaterialReservationPreview = () => {
    const siteSelect = document.querySelector('[data-production-material-site]');
    if (!siteSelect || siteSelect.dataset.previewInitialized === 'true') {
        return;
    }

    siteSelect.dataset.previewInitialized = 'true';
    siteSelect.addEventListener('change', () => siteSelect.form?.requestSubmit());
};

document.addEventListener('DOMContentLoaded', initializeMaterialReservationPreview);
document.addEventListener('turbo:load', initializeMaterialReservationPreview);
