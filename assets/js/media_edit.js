import {updateImagePositions} from "./sortable_image";

export function initMediaEdit() {
  document.querySelectorAll('.photo-item').forEach(photoItem => {
    addMediaEditEvent(photoItem);
  });
  window.baseModal.updateOnShow(modalOnShow)
}

async function modalOnShow() {
  const mediaModalCloseButton = document.getElementById('media_edit_modal_close_button');
  if (!mediaModalCloseButton) return;

  // Event Close Modal Button
  mediaModalCloseButton.addEventListener('click', function () {
    window.baseModal.hide();
  });


  // Delete Media
  const deleteButton = document.getElementById('delete_media');
  const deleteForm = document.getElementById('media_delete_form');

  deleteButton.addEventListener('click', async function (ev) {
    ev.preventDefault();
    if (confirm(this.dataset.confirmMessage)) {
      try {
        const deleteResponse = await fetch(deleteForm.action, {
          method: 'POST',
          body: new FormData(deleteForm),
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
          }
        });
        window.baseModal.hide();
        const deletedImage = document.querySelector(`.photo-item[data-media-id="${this.dataset.mediaId}"]`);
        const container = deletedImage.closest('.image-list');
        deletedImage.remove();
        updateImagePositions(container);
      } catch (error) {
        console.error('Media could not deleted: ', error);
      }
    }
  });
}

export function addMediaEditEvent(el) {
  el.addEventListener('click', async function () {
    const mediaId = this.dataset.mediaId;
    if (!mediaId) return;

    const url = `/media/${mediaId}/edit`;

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      const modalBody = document.getElementById('base_modal_body');
      modalBody.innerHTML = await response.text();
      window.baseModal.show();

    } catch (error) {
      console.error('Error loading modal content:', error);
    }
  });
}