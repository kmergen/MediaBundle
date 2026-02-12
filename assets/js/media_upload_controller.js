import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";

export default class extends Controller {
  uploadUrl = "/media/upload";
  reorderUrl = "/media/reorder";
  deleteUrlTemplate = "/media/ID_PLACEHOLDER";
  editUrlTemplate = "/media/ID_PLACEHOLDER/edit";

  static targets = [
    "input",
    "previewList",
    "mediaIds",
    "dropzone",
    "albumId",
    "tempKey",
    "modal",
    "modalContent",
    "errorBox",
  ];

  static values = {
    albumId: Number,
    tempKey: String,
    csrf: String,
    context: String,
    initialImages: Array,
    autoSave: Boolean,
    imageVariants: Array,
    editableAltTextLocales: String,
    // Diese Werte kommen aus der MediaDashboardConfig (PHP)
    maxFiles: Number,
    maxFileSize: Number,
    allowedMimeTypes: Array,
    translations: Object,
    badgeText: { type: String, default: "Hauptbild" },
  };

  _modal = null;
  _modalContent = null;
  _errorTimeout = null;

  connect() {
    this.initSortable();
    this.renderInitialImages();

    if (this.hasModalTarget) {
      this._modal = this.modalTarget;
      this._modalContent = this.modalContentTarget;

      // Modal Teleport (ans Ende vom Body)
      document.body.appendChild(this._modal);

      this.boundSubmitEdit = this.submitEdit.bind(this);
      this._modalContent.addEventListener("submit", this.boundSubmitEdit);

      this.boundHandleModalClick = this.handleModalClick.bind(this);
      this._modal.addEventListener("click", this.boundHandleModalClick);
    }
  }

  disconnect() {
    if (this._modal) {
      if (this.boundSubmitEdit) {
        this._modalContent.removeEventListener("submit", this.boundSubmitEdit);
      }
      if (this.boundHandleModalClick) {
        this._modal.removeEventListener("click", this.boundHandleModalClick);
      }
      this._modal.remove();
    }
  }

  // --- UI Helpers ---

  initSortable() {
    new Sortable(this.previewListTarget, {
      animation: 150,
      ghostClass: "opacity-50",
      draggable: ".kmm-photo-item",
      onEnd: () => {
        this.updateState();
        if (this.autoSaveValue) this.saveOrder();
      },
    });
  }

  renderInitialImages() {
    if (this.initialImagesValue.length > 0) {
      this.initialImagesValue.forEach((image) => this.addImageToDOM(image));
      this.updateState();
    }
  }

  showError(message) {
    if (this.hasErrorBoxTarget) {
      this.errorBoxTarget.innerText = message;
      this.errorBoxTarget.classList.remove("kmm-hidden");

      if (this._errorTimeout) clearTimeout(this._errorTimeout);
      this._errorTimeout = setTimeout(() => {
        this.errorBoxTarget.classList.add("kmm-hidden");
      }, 5000);
    } else {
      alert(message);
    }
  }

  // --- Events ---

  onDropzoneClick() {
    this.inputTarget.click();
  }
  onFileChange(e) {
    this.handleFiles(e.target.files);
  }
  onDragOver(e) {
    e.preventDefault();
    this.dropzoneTarget.classList.add("kmm-drag-over");
  }
  onDragLeave(e) {
    e.preventDefault();
    this.dropzoneTarget.classList.remove("kmm-drag-over");
  }
  onDrop(e) {
    e.preventDefault();
    this.onDragLeave(e);
    this.handleFiles(e.dataTransfer.files);
  }

  // --- Core Logic: Validation & Upload ---

  /**
   * Validiert eine einzelne Datei (ICU Syntax: {name}, {type}, etc.)
   */
  validateFile(file) {
    const t = this.translationsValue || {};

    // 1. MIME-Type Check
    if (
      this.hasAllowedMimeTypesValue &&
      this.allowedMimeTypesValue.length > 0
    ) {
      if (!this.allowedMimeTypesValue.includes(file.type)) {
        const typeToShow = file.type || "Unbekannt";
        const msg = t.errorFileType
          ? t.errorFileType.replace("{type}", typeToShow)
          : `Dateityp ${typeToShow} ist nicht erlaubt.`;
        this.showError(msg);
        return false;
      }
    }

    // 2. File Size Check
    if (this.maxFileSizeValue > 0) {
      const sizeInMb = file.size / (1024 * 1024);
      if (sizeInMb > this.maxFileSizeValue) {
        const msg = t.errorFileSize
          ? t.errorFileSize
              .replace("{name}", file.name)
              .replace("{size}", sizeInMb.toFixed(1))
              .replace("{limit}", this.maxFileSizeValue)
          : `Datei "${file.name}" ist zu groß`;
        this.showError(msg);
        return false;
      }
    }
    return true;
  }

  /**
   * Hauptmethode für den Datei-Upload
   */
  async handleFiles(files) {
    const fileArray = Array.from(files);
    const t = this.translationsValue || {};

    // 1. Batch-Validierung: Maximale Anzahl Bilder
    // (Hinweis: t.errorMaxFiles kommt vom Server bereits fertig mit der Zahl drin!)
    const currentCount =
      this.previewListTarget.querySelectorAll(".kmm-photo-item").length;
    if (
      this.maxFilesValue > 0 &&
      currentCount + fileArray.length > this.maxFilesValue
    ) {
      this.showError(
        t.errorMaxFiles || `Maximal ${this.maxFilesValue} Bilder erlaubt.`,
      );
      this.inputTarget.value = "";
      return;
    }

    // 2. Einzelverarbeitung
    for (const file of fileArray) {
      // Validierung (Mime/Size) - Platzhalter wird erst danach erstellt
      if (!this.validateFile(file)) continue;

      const tempId = Math.random().toString(36).substring(7);
      this.createLoadingPlaceholder(tempId);

      try {
        const compressedFile = await this.compressImage(file, 1920, 0.8);

        const formData = new FormData();
        formData.append("file", compressedFile);
        formData.append("context", this.contextValue);
        formData.append("autoSave", this.autoSaveValue ? "1" : "0");
        if (!this.autoSaveValue) formData.append("tempKey", this.tempKeyValue);
        if (this.hasAlbumIdValue && this.albumIdValue)
          formData.append("albumId", this.albumIdValue);

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

        if (!response.ok) throw new Error("Server Error");

        const result = await response.json();
        this.removePlaceholder(tempId);

        if (result.id) {
          if (result.albumId) {
            this.albumIdValue = result.albumId;
            if (this.hasAlbumIdTarget)
              this.albumIdTarget.value = result.albumId;
          }
          this.addImageToDOM(result);
          this.updateState();
          if (this.autoSaveValue) this.saveOrder();
        }
      } catch (err) {
        console.error("Upload Error:", err);
        this.removePlaceholder(tempId);

        // Fehler-Message mit ICU Syntax Ersetzung {name}
        const errorMsg = t.errorUpload
          ? t.errorUpload.replace("{name}", file.name)
          : `Fehler beim Upload von ${file.name}`;
        this.showError(errorMsg);
      }
    }
    this.inputTarget.value = "";
  }
  // --- DOM Manipulation ---

  addImageToDOM(imageData, prepend = false) {
    const div = document.createElement("div");
    div.className = "kmm-photo-item";
    div.dataset.mediaId = imageData.id;

    let url = imageData.previewUrl || imageData.url;

    div.innerHTML = `
            <img src="${url}" class="kmm-preview-image" alt="Preview">
            <div class="badge-container"></div>
            <button type="button" data-action="click->media-upload#removeImage" class="kmm-delete-btn" title="Entfernen">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            <button type="button" data-action="click->media-upload#edit" data-media-id="${imageData.id}" class="kmm-edit-btn" title="Bearbeiten">
                 <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
            </button>
        `;

    if (prepend)
      this.previewListTarget.insertAdjacentElement("afterbegin", div);
    else this.previewListTarget.insertAdjacentElement("beforeend", div);
  }

  removeImage(e) {
    const t = this.translationsValue || {};
    if (!confirm(t.confirmDelete || "Bild wirklich entfernen?")) return;

    const item = e.target.closest(".kmm-photo-item");
    const mediaId = item.dataset.mediaId;

    item.remove();
    this.updateState();

    if (this.autoSaveValue && mediaId) this.deleteRemote(mediaId);
  }

  async saveOrder() {
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
    } catch (err) {
      this.showError("Löschen auf dem Server fehlgeschlagen.");
    }
  }

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

    if (this.hasMediaIdsTarget) this.mediaIdsTarget.value = ids.join(",");
  }

  updateMainBadge(item, isMain) {
    const badgeContainer = item.querySelector(".badge-container");
    if (!badgeContainer) return;
    badgeContainer.innerHTML = "";

    if (isMain) {
      item.classList.add("kmm-main-image-border");
      const badge = document.createElement("div");
      badge.className = "kmm-main-image-badge";
      badge.innerText =
        this.translationsValue?.badgeText || this.badgeTextValue;
      badgeContainer.appendChild(badge);
    } else {
      item.classList.remove("kmm-main-image-border");
    }
  }

  createLoadingPlaceholder(tempId) {
    const div = document.createElement("div");
    div.id = `temp-${tempId}`;
    div.className = "kmm-photo-item";
    div.innerHTML = `<svg class="kmm-placeholder-spin" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5a.75.75 0 0 1 1.5 0c0 5.523-4.477 10-10 10S2 17.523 2 12S6.477 2 12 2a.75.75 0 0 1 0 1.5"/></svg>`;
    this.previewListTarget.insertAdjacentElement("beforeend", div);
  }

  removePlaceholder(tempId) {
    document.getElementById(`temp-${tempId}`)?.remove();
  }

  async compressImage(file, maxWidth, quality) {
    if (!file.type.startsWith("image/")) return file; // Nur Bilder komprimieren
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

  // --- Modal (Edit) ---

  async edit(event) {
    event.preventDefault();
    const mediaId = event.currentTarget.dataset.mediaId;
    if (!mediaId) return;

    let url = this.editUrlTemplate.replace("ID_PLACEHOLDER", mediaId);
    const params = new URLSearchParams();
    params.append("t", Date.now());
    if (this.editableAltTextLocalesValue)
      params.append("locales", this.editableAltTextLocalesValue);
    url += "?" + params.toString();

    this._modal.classList.remove("kmm-hidden");
    this._modalContent.innerHTML = `<div style="display:flex; justify-content:center; padding:3rem;"><svg class="kmm-placeholder-spin" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5a.75.75 0 0 1 1.5 0c0 5.523-4.477 10-10 10S2 17.523 2 12S6.477 2 12 2a.75.75 0 0 1 0 1.5"/></svg></div>`;

    try {
      const response = await fetch(url, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      this._modalContent.innerHTML = await response.text();
      const input = this._modalContent.querySelector(
        "input:not([type='hidden'])",
      );
      if (input) input.focus();
    } catch (e) {
      this._modalContent.innerHTML = "Fehler beim Laden.";
    }
  }

  async submitEdit(event) {
    event.preventDefault();
    const form = event.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerText = "Speichern...";
    }

    try {
      const response = await fetch(form.action, {
        method: form.method,
        body: new FormData(form),
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      if (
        response.ok &&
        response.headers.get("content-type")?.includes("application/json")
      ) {
        this.updateItemInDOM(await response.json());
        this.closeModal();
      } else {
        this._modalContent.innerHTML = await response.text();
      }
    } catch (e) {
      if (submitBtn) submitBtn.innerText = "Fehler";
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  updateItemInDOM(data) {
    const item = this.previewListTarget.querySelector(
      `.kmm-photo-item[data-media-id="${data.id}"]`,
    );
    if (!item) return;
    const img = item.querySelector(".kmm-preview-image");
    if (img && data.alt !== undefined) img.alt = data.alt;
    item.style.boxShadow = "0 0 0 4px #22c55e";
    setTimeout(() => (item.style.boxShadow = ""), 1000);
  }

  handleModalClick(event) {
    if (
      event.target.closest('[data-action*="closeModal"]') ||
      event.target.classList.contains("kmm-modal-backdrop")
    ) {
      this.closeModal();
    }
  }

  closeModal() {
    this._modal.classList.add("kmm-hidden");
    this._modalContent.innerHTML = "";
  }
}
