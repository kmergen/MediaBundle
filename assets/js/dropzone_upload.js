import Dropzone from 'dropzone';
import 'dropzone/dist/dropzone.css';
import {addMediaEditEvent} from "./media_edit";

export async function initUpload() {

  // Find all Dropzone containers
  const dropzoneContainers = document.querySelectorAll('.dropzone-container');

  if (!dropzoneContainers.length) {
    // console.warn('No Dropzone containers found'); // Debugging
    return;
  }

  // Extract base path for URLs
  const basePath = window.location.pathname.split('/').slice(0, 2).join('/');

  // Loop through each Dropzone container and initialize Dropzone
  for (const container of dropzoneContainers) {
    // console.log('Initializing Dropzone for container:', container); // Debugging

    // Find the associated settings for this Dropzone instance
    const dropzoneWrapper = container.closest('.dropzone-wrapper');
    if (!dropzoneWrapper) {
      console.warn('Dropzone wrapper not found'); // Debugging
      continue; // Skip to the next container
    }

    // Get settings from data attributes
    const uploadUrl = dropzoneWrapper.dataset.uploadUrl;
    const imageListUrl = dropzoneWrapper.dataset.imageListUrl;
    const entityId = dropzoneWrapper.dataset.entityId;
    const entityName = dropzoneWrapper.dataset.entityName;
    const targetDir = dropzoneWrapper.dataset.targetDir;
    const tempKey = dropzoneWrapper.dataset.tempKey || null;
    const maxFilesize = dropzoneWrapper.dataset.maxFilesize || 16;
    const imageVariants = JSON.parse(dropzoneWrapper.dataset.imageVariants || '[]');
    const maxFiles = dropzoneWrapper.dataset.maxFiles || 5;

    // Initialize Dropzone for the current container
    try {
      const dropzone = new Dropzone(container, {
        url: uploadUrl,
        createImageThumbnails: true,
        disablePreviews: true,
        clickable: container, // Ensure the container is clickable
        maxFiles,
      });

      // Load initial image list via AJAX
      await loadImageList(imageListUrl, entityName, entityId, container);

      // Handle file upload events
      dropzone.on("sending", function (file, xhr, formData) {
        // Show the spinner when the file starts uploading
        const spinner = container.querySelector('.upload-spinner');
        if (spinner) {
          spinner.classList.remove('hidden');
          spinner.classList.add('flex');
        }

        // Append entity data
        if (entityId !== null && entityId !== '') {
          formData.append('entityId', entityId);
        }
        formData.append('entityName', entityName);
        formData.append('targetDir', targetDir);
        // Only append tempKey if it is not null and not an empty string
        if (tempKey !== null && tempKey !== '') {
          formData.append('tempKey', tempKey);
        }

        formData.append('maxFilesize', maxFilesize);
        imageVariants.forEach((variant, index) => {
          formData.append(`image_variants[${index}]`, variant);
        });

        // Optional Find the associated form for this Dropzone instance
        // If you want to send additional data with a form.
        const dropzoneWrapper = container.closest('.dropzone-wrapper');
        if (dropzoneWrapper) {
          const form = dropzoneWrapper.querySelector('.dropzone-form');
          if (form) {
            const additionalData = new FormData(form);
            for (let pair of additionalData.entries()) {
              formData.append(pair[0], pair[1]);
            }
          }
        } else {
          console.warn('Dropzone wrapper not found'); // Debugging
        }
      });

      dropzone.on("success", async function (file, response) {
        // Refresh the image list after successful upload
        await loadImageList(imageListUrl, entityName, entityId, container, response.media.id);

        // Hide the spinner after the upload is successful
        const spinner = container.querySelector('.upload-spinner');
        if (spinner) {
          spinner.classList.remove('flex');
          spinner.classList.add('hidden');
        }
      });
      // Listen for the "maxfilesreached" event
      dropzone.on("maxfilesreached", function (file) {
        console.log("Max files reached!"); // Debugging

        // Disable the Dropzone container
        dropzone.disable();

        // Add a custom class for additional styling
        dropzone.element.classList.add("max-files-reached-out-just-annother-hero");

        // Show a custom message
        const message = document.createElement("div");
        message.className = "max-files-message";
        message.textContent = "You have reached the maximum number of files.";
        dropzone.element.appendChild(message);
      });

      dropzone.on("error", function (file, errorMessage) {
        console.error('Error uploading file:', errorMessage); // Debugging
      });
    } catch (error) {
      console.error('Error initializing Dropzone for container:', container, error); // Debugging
    }
  }
}

// Function to load the image list via AJAX
async function loadImageList(url, entityName, entityId, container, mediaId = null) {
  const data = {entityName: entityName, entityId: entityId};
  if (mediaId) {
    data.mediaId = mediaId;
  }

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(data),
    });

    // Check if the response is OK (status code 2xx)
    if (!response.ok) {
      console.error(`Failed to load image list: ${response.statusText}`);
      return; // Exit the function early
    }

    const html = await response.text();

    // Find the image list container within the current Dropzone instance
    const imageListContainer = container.closest('.dropzone-wrapper')?.querySelector('.image-list');
    if (!imageListContainer) {
      console.warn('Image list container not found'); // Debugging
      return;
    }

    // Append the new image HTML to the image list container
    imageListContainer.querySelector('.upload-spinner').insertAdjacentHTML('beforebegin', html);

    // Add edit event to the new image (if mediaId is provided)
    if (mediaId) {
      const newImageElement = imageListContainer.querySelector(`.photo-item[data-media-id="${mediaId}"]`);
      if (newImageElement) {
        addMediaEditEvent(newImageElement);
      } else {
        console.warn('New image element not found for mediaId:', mediaId); // Debugging
      }
    }
  } catch (error) {
    console.error('Error loading image list:', error); // Debugging
  }


}