import { Controller } from "@hotwired/stimulus";
import Sortable from "sortablejs";

export default class extends Controller {
  static targets = ["input", "previewList", "hiddenInput", "dropzone"];

  static values = {
    // URLs
    uploadUrl: String,
    // listUrl ist WEG - brauchen wir nicht mehr!

    // Context
    albumId: { type: Number, default: null },

    // Data
    initialImages: { type: Array, default: [] }, // HIER kommen die Start-Bilder rein

    // Config
    maxFiles: { type: Number, default: 10 },
    targetDir: String,
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
      draggable: ".photo-item",
      onEnd: () => this.updateState(),
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
        if (this.hasAlbumIdValue) formData.append("albumId", this.albumIdValue);
        if (this.hasTargetDirValue)
          formData.append("targetDir", this.targetDirValue);
        if (this.hasImageVariantsValue) {
          this.imageVariantsValue.forEach((v, i) =>
            formData.append(`image_variants[${i}]`, v),
          );
        }

        const response = await fetch(this.uploadUrlValue, {
          method: "POST",
          headers: { "X-Requested-With": "XMLHttpRequest" },
          body: formData,
        });

        const result = await response.json();

        // 4. Platzhalter weg
        this.removePlaceholder(tempId);

        if (result.id) {
          if (!this.albumIdValue && result.albumId) {
            this.albumIdValue = result.albumId;
            // Optional: Aktualisiere das data-attribute im DOM, falls nötig
          }
          // 5. Neues Element rendern (JSON -> HTML)
          // Wir erwarten vom Server: { id: 123, url: '/pfad/zum/bild.jpg' }
          this.addImageToDOM(result); // true = vorne anfügen
          this.updateState();
        } else {
          alert("Upload fehlgeschlagen: " + (result.error || "Unbekannt"));
        }
      } catch (err) {
        console.error("Upload Error:", err);
        this.removePlaceholder(tempId);
        alert("Ein Fehler ist aufgetreten.");
      }
    }
    this.inputTarget.value = "";
  }

  // --- Rendering (Der WP-Teil) ---

  /**
   * Baut das HTML für ein Bild und fügt es ein
   */
  addImageToDOM(imageData, prepend = false) {
    const div = document.createElement("div");

    // Klassen für das Item Container
    div.className =
      "photo-item relative w-[140px] h-[140px] group mb-4 overflow-hidden rounded-lg border border-gray-200 shadow-sm bg-white cursor-move select-none";
    div.dataset.mediaId = imageData.id;

    // Sicherstellen, dass URL passt
    let url = imageData.previewUrl || imageData.url; // Fallback, falls Server 'url' statt 'previewUrl' sendet

    // Das HTML Template (Dein Premium Look)
    div.innerHTML = `
            <!-- Layer 1: Hintergrund Blur -->
            <img src="${url}" class="absolute inset-0 w-full h-full object-cover blur-md opacity-60 scale-110 pointer-events-none z-0">
            
            <!-- Layer 2: Bild Scharf -->
            <img src="${url}" class="relative w-full h-full object-contain pointer-events-none z-10 drop-shadow-sm" alt="Preview">
            
            <!-- Badge Platzhalter (wird via JS gefüllt) -->
            <div class="badge-container"></div>

            <!-- Löschen Button -->
            <button type="button" 
                    data-action="click->media-upload#removeImage"
                    class="absolute top-1 right-1 z-30 bg-white text-red-600 hover:bg-red-50 rounded-full p-1 shadow-md opacity-0 group-hover:opacity-100 transition cursor-pointer" 
                    title="Entfernen">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
            
            <!-- Optional: Edit Button (für Modal) -->
            <button type="button" 
                    data-action="click->media-upload#edit"
                    data-media-id="${imageData.id}" 
                    class="absolute top-1 right-8 z-30 bg-white text-gray-700 hover:bg-gray-50 rounded-full p-1 shadow-md opacity-0 group-hover:opacity-100 transition cursor-pointer"
                    title="Bearbeiten">
                 <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
    // Simples Entfernen aus dem DOM.
    // Da die ID dann nicht mehr im hiddenInput landet,
    // löscht Symfony beim Speichern des Formulars die Relation (Orphan Removal).
    if (confirm("Bild wirklich entfernen?")) {
      const item = e.target.closest(".photo-item");
      item.remove();
      this.updateState();
    }
  }

  // --- State Management ---

  updateState() {
    const ids = [];
    const items = Array.from(this.previewListTarget.children).filter((el) =>
      el.classList.contains("photo-item"),
    );

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

  updateMainBadge(item, isMain) {
    const badgeContainer = item.querySelector(".badge-container");
    if (!badgeContainer) return; // Sicherheitscheck

    // Altes Badge entfernen
    badgeContainer.innerHTML = "";

    if (isMain) {
      item.classList.add("ring-4", "ring-blue-600", "ring-offset-2");

      // Badge HTML erzeugen
      const badge = document.createElement("div");
      badge.className =
        "absolute bottom-2 left-1/2 -translate-x-1/2 bg-blue-600/90 text-white text-[10px] font-bold py-1 px-3 rounded-full uppercase z-20 pointer-events-none backdrop-blur-sm shadow-lg whitespace-nowrap";
      badge.innerText = this.badgeTextValue;

      badgeContainer.appendChild(badge);
    } else {
      item.classList.remove("ring-4", "ring-blue-600", "ring-offset-2");
    }
  }

  // --- Helpers ---

  createLoadingPlaceholder(tempId) {
    const div = document.createElement("div");
    div.id = `temp-${tempId}`;
    div.className =
      "photo-item relative w-[140px] h-[140px] bg-gray-50 rounded-lg border-2 border-dashed border-gray-200 flex items-center justify-center mb-4";
    div.innerHTML = `<svg class="animate-spin h-6 w-6 text-blue-500" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>`;

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
