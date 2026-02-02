import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";

export default class extends Controller {
  static targets = ["input", "previewList", "hiddenInput", "dropzone"];
  static values = {
    // Url
    uploadUrl: String,
    listUrl: String,

    // Entity Context (WICHTIG: Müssen im HTML gesetzt sein!)
    albumId: Number,

    // Config
    targetDir: String,
    imageVariants: Array,
  };

  connect() {
    this.initSortable();
    this.loadInitialState();
    //this.preloadExistingImages();
  }

  initSortable() {
    new Sortable(this.previewListTarget, {
      animation: 150,
      ghostClass: "opacity-50",
      draggable: ".photo-item",
      onEnd: () => this.updateState(),
    });
  }

  /**
   * Lädt die komplette Liste beim Start
   */
  async loadInitialState() {
    // Ruft fetchImages ohne ID auf -> PHP liefert alle Bilder
    await this.fetchImages();
    this.updateState();
  }

  preloadExistingImages() {
    if (this.hasPreloadedValue && Array.isArray(this.preloadedValue)) {
      this.preloadedValue.forEach((img) => {
        // Wir nutzen hier das echte UI-Item Template
        this.renderImageItem(img);
      });
      this.updateState();
    }
  }

  // --- Upload Events ---
  onDropzoneClick() {
    this.inputTarget.click();
  }

  onFileChange(e) {
    this.handleFiles(e.target.files);
  }

  onDragOver(e) {
    e.preventDefault();
    this.dropzoneTarget.classList.add(
      "border-blue-500",
      "bg-blue-50",
      "ring-2",
      "ring-blue-200",
    );
  }

  onDragLeave(e) {
    e.preventDefault();
    this.dropzoneTarget.classList.remove(
      "border-blue-500",
      "bg-blue-50",
      "ring-2",
      "ring-blue-200",
    );
  }

  onDrop(e) {
    e.preventDefault();
    this.onDragLeave(e);
    this.handleFiles(e.dataTransfer.files);
  }

  async handleFiles(files) {
    for (const file of Array.from(files)) {
      if (!file.type.startsWith("image/")) continue;

      const tempId = Math.random().toString(36).substring(7);
      this.createLoadingPlaceholder(tempId);

      try {
        const compressedFile = await this.compressImage(file, 1920, 0.8);
        const formData = new FormData();
        formData.append("file", compressedFile);

        // Context Daten senden
        if (this.hasAlbumIdValue) formData.append("albumId", this.albumIdValue);
        if (this.hasTargetDirValue)
          formData.append("targetDir", this.targetDirValue);

        if (this.hasImageVariantsValue) {
          this.imageVariantsValue.forEach((variant, index) => {
            formData.append(`image_variants[${index}]`, variant);
          });
        }

        const response = await fetch(this.uploadUrlValue, {
          method: "POST",
          headers: { "X-Requested-With": "XMLHttpRequest" },
          body: formData,
        });

        const uploadResult = await response.json();

        if (uploadResult.id) {
          // ERFOLG: Wir rufen fetchImages MIT der ID auf
          // PHP liefert nur dieses eine Bild
          await this.fetchImages(uploadResult.id, tempId);
          this.updateState();
        } else {
          this.removePlaceholder(tempId);
          alert("Upload fehlgeschlagen");
        }
      } catch (err) {
        console.error("Fehler im Upload-Prozess:", err);
        this.removePlaceholder(tempId);
      }
    }
    this.inputTarget.value = "";
  }
  /**
   * Die ZENTRALE Funktion für HTML-Updates
   * mediaId = null -> Initialer Load (alles ersetzen)
   * mediaId = int  -> Einzelnes Bild (appenden)
   */
  async fetchImages(mediaId = null, tempId = null) {
    try {
      // Grund-Payload immer mit Album ID
      const payload = {
        albumId: this.albumIdValue,
      };

      // Wenn spezifisches Bild (nach Upload), ID hinzufügen
      if (mediaId !== null) {
        payload.mediaId = mediaId;
      }

      const response = await fetch(this.listUrlValue, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/json",
        },
        body: JSON.stringify(payload),
      });

      if (response.ok) {
        const html = await response.text();

        if (mediaId === null) {
          // Initial Load: Liste komplett
          this.previewListTarget.innerHTML = html;
        } else {
          // Upload Update: Einzelnes Bild
          if (tempId) this.removePlaceholder(tempId);
          this.previewListTarget.insertAdjacentHTML("beforeend", html);
        }

        // Nach jedem Fetch State updaten (für Hidden Input etc.)
        this.updateState();
      } else {
        console.error("Server Fehler beim Laden der Bilder");
        if (tempId) this.removePlaceholder(tempId);
      }
    } catch (err) {
      console.error("Netzwerk Fehler:", err);
      if (tempId) this.removePlaceholder(tempId);
    }
  }

  async fetchAndAppendImageHtml(mediaId, tempId) {
    try {
      const response = await fetch(this.listUrlValue, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          entityName: this.entityNameValue,
          entityId: this.entityIdValue,
          mediaId: mediaId, // Wichtig: Nur das eine neue Bild anfordern
        }),
      });

      if (response.ok) {
        const html = await response.text();

        // Den Platzhalter durch das echte HTML ersetzen oder davor einfügen
        this.removePlaceholder(tempId);
        this.listTarget.insertAdjacentHTML("afterbegin", html);

        // Falls du Events binden musst (z.B. Edit-Modal)
        const newElement = this.listTarget.querySelector(
          `[data-media-id="${mediaId}"]`,
        );
        if (newElement && typeof this.addMediaEditEvent === "function") {
          this.addMediaEditEvent(newElement);
        }
      }
    } catch (err) {
      console.error("Fehler beim Laden des HTML-Fragments:", err);
    }
  }

  // --- Edit & Modal Logik (Deine Media-Edit Transformation) ---
  async edit(e) {
    const mediaId = e.currentTarget.dataset.mediaId;
    const url = `/media/${mediaId}/edit`;

    try {
      const response = await fetch(url, {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      const html = await response.text();

      const modalBody = document.getElementById("base_modal_body");
      if (modalBody) {
        modalBody.innerHTML = html;
        window.baseModal.show();
        this.bindModalEvents(mediaId);
      }
    } catch (error) {
      console.error("Modal Fehler:", error);
    }
  }

  bindModalEvents(mediaId) {
    // Schließen Button im Modal
    document
      .getElementById("media_edit_modal_close_button")
      ?.addEventListener("click", () => {
        window.baseModal.hide();
      });

    // Löschen Button im Modal
    const deleteBtn = document.getElementById("delete_media");
    if (deleteBtn) {
      deleteBtn.addEventListener("click", async (ev) => {
        ev.preventDefault();
        if (confirm(deleteBtn.dataset.confirmMessage)) {
          await this.executeDelete(mediaId);
        }
      });
    }
  }

  async executeDelete(mediaId) {
    const deleteForm = document.getElementById("media_delete_form");
    try {
      const response = await fetch(deleteForm.action, {
        method: "POST",
        body: new FormData(deleteForm),
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });

      if (response.ok) {
        window.baseModal.hide();
        const item = this.previewListTarget.querySelector(
          `[data-media-id="${mediaId}"]`,
        );
        item?.remove();
        this.updateState();
      }
    } catch (error) {
      console.error("Löschen fehlgeschlagen:", error);
    }
  }

  // --- State & UI Hilfsmethoden ---
  updateState() {
    const ids = [];
    const items = Array.from(this.previewListTarget.children);

    items.forEach((item, index) => {
      if (item.dataset.mediaId) {
        ids.push(item.dataset.mediaId);
        this.updateMainBadge(item, index === 0);
      }
    });

    if (this.hasHiddenInputTarget) {
      this.hiddenInputTarget.value = ids.join(",");
    }
  }

  createImageElement(imageData) {
    let url = imageData.url;

    // Slash-Fix: Falls die URL nicht mit / oder http anfängt, vorne einen Slash dran
    if (!url.startsWith("/") && !url.startsWith("http")) {
      url = "/" + url;
    }

    const div = document.createElement("div");
    // Gleiche Klassen wie in deiner CSS/HTML Struktur (140x140px)
    div.classList.add("relative", "w-[140px]", "h-[140px]", "group", "mb-4");
    div.dataset.id = imageData.id;

    div.innerHTML = `
        <img src="${url}" class="w-full h-full object-cover rounded-lg border border-gray-200 shadow-sm">
        <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-10 transition-all rounded-lg"></div>
        <button type="button" 
                data-action="click->media-upload#removeImage" 
                class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity shadow-lg">
            ×
        </button>
    `;
    return div;
  }

  createLoadingPlaceholder(tempId) {
    const div = document.createElement("div");
    div.id = `temp-${tempId}`;
    div.className =
      "relative aspect-square bg-gray-50 rounded-lg border-2 border-dashed border-gray-200 flex items-center justify-center";
    div.innerHTML = `<svg class="animate-spin h-6 w-6 text-blue-500" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>`;
    this.previewListTarget.appendChild(div);
  }

  removePlaceholder(tempId) {
    document.getElementById(`temp-${tempId}`)?.remove();
  }
  async fetchAndAppendImageHtml(mediaId, tempId) {
    try {
      const response = await fetch(this.listUrlValue, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          entityName: this.entityNameValue,
          entityId: this.entityIdValue,
          mediaId: mediaId, // Wichtig: Nur das eine neue Bild anfordern
        }),
      });

      if (response.ok) {
        const html = await response.text();

        // Den Platzhalter durch das echte HTML ersetzen oder davor einfügen
        this.removePlaceholder(tempId);
        this.listTarget.insertAdjacentHTML("afterbegin", html);

        // Falls du Events binden musst (z.B. Edit-Modal)
        const newElement = this.listTarget.querySelector(
          `[data-media-id="${mediaId}"]`,
        );
        if (newElement && typeof this.addMediaEditEvent === "function") {
          this.addMediaEditEvent(newElement);
        }
      }
    } catch (err) {
      console.error("Fehler beim Laden des HTML-Fragments:", err);
    }
  }

  updateMainBadge(itemElement, isMain) {
    const existingBadge = itemElement.querySelector(".main-badge");
    if (isMain) {
      itemElement.classList.add("ring-4", "ring-blue-600");
      if (!existingBadge) {
        const badge = document.createElement("div");
        badge.className =
          "main-badge absolute bottom-2 left-1/2 -translate-x-1/2 bg-blue-600/90 text-white text-[10px] font-bold py-1 px-3 rounded-full uppercase z-20 pointer-events-none backdrop-blur-sm";
        badge.innerText = "Hauptbild";
        itemElement.appendChild(badge);
      }
    } else {
      itemElement.classList.remove("ring-4", "ring-blue-600");
      existingBadge?.remove();
    }
  }

  async compressImage(file, maxWidth, quality) {
    return new Promise((resolve) => {
      const reader = new FileReader();
      reader.readAsDataURL(file);
      reader.onload = (e) => {
        const img = new Image();
        img.src = e.target.result;
        img.onload = () => {
          const canvas = document.createElement("canvas");
          let width = img.width,
            height = img.height;
          if (width > maxWidth) {
            height *= maxWidth / width;
            width = maxWidth;
          }
          canvas.width = width;
          canvas.height = height;
          canvas.getContext("2d").drawImage(img, 0, 0, width, height);
          canvas.toBlob(
            (blob) => {
              resolve(
                new File([blob], file.name.replace(/\.[^/.]+$/, "") + ".jpg", {
                  type: "image/jpeg",
                }),
              );
            },
            "image/jpeg",
            quality,
          );
        };
      };
    });
  }
}
