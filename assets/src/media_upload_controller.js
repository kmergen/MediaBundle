// media-bundle/assets/src/media_upload_controller.js
import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";

export default class extends Controller {
  uploadUrl = "/media/upload";
  reorderUrl = "/media/reorder";
  deleteUrlTemplate = "/media/ID_PLACEHOLDER";

  static targets = [
    "input",
    "previewList",
    "mediaIds",
    "dropzone",
    "albumId",
    "tempKey",
  ];

  static values = {
    albumId: Number,
    tempKey: String,
    csrf: String,
    context: String,
    initialImages: Array,
    autoSave: Boolean,
    type: Number,
    imageVariants: Array,

    // UI Texte (optional für Übersetzung)
    badgeText: { type: String, default: "Hauptbild" },
  };

  connect() {
    this.initSortable();
    this.renderInitialImages();
  }

  initSortable() {
    new Sortable(this.previewListTarget, {
      animation: 150,
      ghostClass: "opacity-50",
      draggable: ".kmm-photo-item",
      onEnd: () => {
        this.updateState();
        // Wenn Autosave an ist, Sortierung sofort senden
        if (this.autoSaveValue) {
          this.saveOrder();
        }
      },
    });
  }

  /**
   * Start-Zustand: Wir rendern die Bilder aus dem JSON
   */
  renderInitialImages() {
    if (this.initialImagesValue.length > 0) {
      this.initialImagesValue.forEach((image) => {
        this.addImageToDOM(image);
      });
      this.updateState();
    }
  }

  // --- Upload Logic ---

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
    );
  }

  onDragLeave(e) {
    e.preventDefault();
    this.dropzoneTarget.classList.remove(
      "border-blue-500",
      "bg-blue-50",
      "ring-2",
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

      // 1. Platzhalter erstellen
      const tempId = Math.random().toString(36).substring(7);
      this.createLoadingPlaceholder(tempId);

      try {
        // 2. Komprimieren
        const compressedFile = await this.compressImage(file, 1920, 0.8);

        // 3. Upload senden
        const formData = new FormData();
        formData.append("file", compressedFile);
        formData.append("context", this.contextValue);
        formData.append("autoSave", this.autoSaveValue ? "1" : "0");

        if (!this.autoSaveValue) {
          formData.append("tempKey", this.tempKeyValue);
        }

        if (this.hasAlbumIdValue && this.albumIdValue) {
          formData.append("albumId", this.albumIdValue);
        }

        if (this.hasImageVariantsValue) {
          this.imageVariantsValue.forEach((v, i) =>
            formData.append(`imageVariants[${i}]`, v),
          );
        }

        const response = await fetch(this.uploadUrl, {
          method: "POST",
          headers: { "X-Requested-With": "XMLHttpRequest" },
          body: formData,
        });

        const result = await response.json();

        // 4. Platzhalter weg
        this.removePlaceholder(tempId);

        if (result.id) {
          if (result.albumId) {
            this.albumIdValue = result.albumId; // Sync für nächsten Loop
            if (this.hasAlbumIdTarget) {
              this.albumIdTarget.value = result.albumId;
            }
          }
          this.addImageToDOM(result);
          this.updateState();
          if (this.autoSaveValue) this.saveOrder();
        }
      } catch (err) {
        console.error("Upload Error:", err);
        this.removePlaceholder(tempId);
      }
    }
    this.inputTarget.value = "";
  }

  /**
   * Baut das HTML für ein Bild und fügt es ein
   */
  addImageToDOM(imageData, prepend = false) {
    const div = document.createElement("div");

    // Klassen für das Item Container
    div.className = "kmm-photo-item";
    div.dataset.mediaId = imageData.id;

    // Sicherstellen, dass URL passt
    let url = imageData.previewUrl || imageData.url; // Fallback, falls Server 'url' statt 'previewUrl' sendet

    // Das HTML Template (Dein Premium Look)
    div.innerHTML = `
             <!-- 
               VARIANTE NEU: Simple Cover (Das Bild füllt den Container komplett) 
               Wichtig: Im CSS (oder Tailwind) braucht dieses IMG:
               width: 100%; height: 100%; object-fit: cover;
            -->
            <img src="${url}" class="kmm-preview-image-cover" alt="Preview" style="width: 100%; height: 100%; object-fit: cover; position: absolute; top: 0; left: 0;">
            
            <!-- 
               VARIANTE ALT: Background Blur (Auskommentiert)
               Falls du zurück willst, den Teil oben löschen und das hier einkommentieren:
            
            <img src="${url}" class="kmm-preview-bg-image">
            <img src="${url}" class="kmm-preview-image" alt="Preview">
            -->
            
            <!-- Badge Platzhalter (wird via JS gefüllt) -->
            <div class="badge-container"></div>

            <!-- Löschen Button -->
            <button type="button" 
                    data-action="click->media-upload#removeImage"
                    class="kmm-delete-btn" 
                    title="Entfernen">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
            
            <!-- Optional: Edit Button (für Modal) -->
            <button type="button" 
                    data-action="click->media-upload#edit"
                    data-media-id="${imageData.id}" 
                    class="kmm-edit-btn"
                    title="Bearbeiten">
                 <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                 </svg>
            </button>
        `;

    if (prepend) {
      this.previewListTarget.insertAdjacentElement("afterbegin", div);
    } else {
      this.previewListTarget.insertAdjacentElement("beforeend", div);
    }
  }

  removeImage(e) {
    if (!confirm("Bild wirklich entfernen?")) return;

    const item = e.target.closest(".kmm-photo-item");
    const mediaId = item.dataset.mediaId;

    // UI sofort aktualisieren (Optimistic UI)
    item.remove();
    this.updateState();

    // Server Request bei Autosave
    if (this.autoSaveValue && mediaId) {
      this.deleteRemote(mediaId);
    }
  }

  // Nur für Autosave Modus
  async saveOrder() {
    // IDs direkt aus den Daten-Attributen der Bilder im DOM lesen
    const ids = Array.from(
      this.previewListTarget.querySelectorAll(".kmm-photo-item"),
    )
      .map((el) => el.dataset.mediaId)
      .filter(Boolean);

    if (ids.length === 0) return;

    try {
      await fetch(this.reorderUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({ ids: ids }),
      });
    } catch (err) {
      console.error("Reorder failed", err);
    }
  }

  // Nur für Autosave Modus
  async deleteRemote(id) {
    const url = this.deleteUrlTemplate.replace("ID_PLACEHOLDER", id);

    try {
      await fetch(url, {
        method: "DELETE",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-TOKEN": this.csrfValue,
        },
      });
      console.log(`Image ${id} deleted permanently`);
    } catch (err) {
      console.error("Delete failed", err);
      alert("Fehler beim Löschen auf dem Server.");
      // Optional: Bild wieder ins DOM einfügen (Rollback)
    }
  }

  // --- State Management ---

  updateState() {
    const ids = [];
    const items = Array.from(this.previewListTarget.children).filter((el) =>
      el.classList.contains("kmm-photo-item"),
    );

    items.forEach((item, index) => {
      if (item.dataset.mediaId) {
        ids.push(item.dataset.mediaId);
        this.updateMainBadge(item, index === 0);
      }
    });

    if (this.hasMediaIdsTarget) {
      this.mediaIdsTarget.value = ids.join(",");
    }
  }

  updateMainBadge(item, isMain) {
    const badgeContainer = item.querySelector(".badge-container");
    if (!badgeContainer) return; // Sicherheitscheck

    // Altes Badge entfernen
    badgeContainer.innerHTML = "";

    if (isMain) {
      item.classList.add("kmm-main-image-border");

      // Badge HTML erzeugen
      const badge = document.createElement("div");
      badge.className = "kmm-main-image-badge";
      badge.innerText = this.badgeTextValue;

      badgeContainer.appendChild(badge);
    } else {
      item.classList.remove("kmm-main-image-border");
    }
  }

  // --- Helpers ---

  createLoadingPlaceholder(tempId) {
    const div = document.createElement("div");
    div.id = `temp-${tempId}`;
    div.className = "kmm-photo-item";
    div.innerHTML = `<svg class="kmm-placeholder-spin" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5a.75.75 0 0 1 1.5 0c0 5.523-4.477 10-10 10S2 17.523 2 12S6.477 2 12 2a.75.75 0 0 1 0 1.5"/></svg>`;

    // Neue Uploads kommen vorne hin
    this.previewListTarget.insertAdjacentElement("beforeend", div);
  }

  removePlaceholder(tempId) {
    document.getElementById(`temp-${tempId}`)?.remove();
  }

  // Modal Logik (Edit) bleibt erhalten, falls du sie noch brauchst
  async edit(e) {
    const mediaId = e.currentTarget.dataset.mediaId;
    // ... deine Modal Logik hier ...
    // Tipp: Da wir pure JS sind, musst du hier evtl. den Modal-Inhalt doch fetchen,
    // oder du lässt das Bearbeiten weg, wenn es nur um Sortieren/Löschen geht.
    console.log("Edit ID:", mediaId);
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
          const ctx = canvas.getContext("2d");
          ctx.drawImage(img, 0, 0, width, height);
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
