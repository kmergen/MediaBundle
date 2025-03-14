// mediaBundle.js
import {initUpload} from './dropzone_upload';
import {initMediaEdit} from './media_edit';
import {initSortableImage} from './sortable_image';

// Initialize all functions
document.addEventListener('DOMContentLoaded', async () => {
  try {
    await initUpload();
  } catch (error) {
    // Silently catch errors for initUpload
    console.error('initUpload failed:', error);
  }

  initSortableImage();
  initMediaEdit();
});
// Optionally, you can export the functions if needed
export {initUpload, initMediaEdit, initSortableImage};