import Sortable from 'sortablejs';

export function initSortableImage() {

  // Find all Sortable containers
  const sortableContainers = document.querySelectorAll('.image-list');

  // If no Sortable containers are found, exit early
  if (!sortableContainers.length) {
    // console.log('No Sortable containers found. Skipping initialization.'); // Debugging
    return;
  }

  // Proceed with Sortable initialization
  for (const container of sortableContainers) {
    // console.log('Initializing Sortable for container:', container); // Debugging
    Sortable.create(container, {
      animation: 150,
      filter: ".dropzone-dashboard",
      onUpdate: function (event) {
        updateImagePositions(container);
        // console.log('Item moved:', event.item); // Debugging
        // Your existing Sortable update logic...
      },
    });
  }
}

export function updateImagePositions(container) {

  const updateUrl = container.dataset.sortableUpdateUrl;
  if (!updateUrl) {
    // console.warn('Sortable update URL not found'); // Debugging
    return;
  }

  // Collect new positions
  const positions = {};
  container.querySelectorAll('.photo-item').forEach((item, index) => {
    positions[item.dataset.mediaId] = index + 1; // Assign new positions based on DOM order
  });

  // Send updated positions to the server
  fetch(updateUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify({positions: positions}),
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(`Failed to update positions: ${response.statusText}`);
      }
      return response.json();
    })
    .then((data) => {
      // console.log('Positions updated successfully:', data); // Debugging
    })
    .catch((error) => {
      // console.error('Error updating positions:', error); // Debugging
    });
}
