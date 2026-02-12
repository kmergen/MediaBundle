// media-bundle/assets/src/media_upload_controller.js
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
    editableAltTextLocales: String, 

    // UI Texte (optional für Übersetzung)
    badgeText: { type: String, default: "Hauptbild" },
  };

  _modal = null;
  _modal_content = null;

  connect() {
    this.initSortable();
    this.renderInitialImages();

    if (this.hasModalTarget) {
      this._modal = this.modalTarget;
      this._modalContent = this.modalContentTarget;

      // 1. Modal Teleport (ans Ende vom Body)
      document.body.appendChild(this._modal);

      // 2. Listener für das FORMULAR (Submit)
      this.boundSubmitEdit = this.submitEdit.bind(this);
      this._modalContent.addEventListener("submit", this.boundSubmitEdit);

      // 3. NEU: Listener für KLICKS (Schließen / Abbrechen / Backdrop)
      // Wir binden eine Funktion, die alle Klicks im Modal überwacht
      this.boundHandleModalClick = this.handleModalClick.bind(this);
      this._modal.addEventListener("click", this.boundHandleModalClick);
    }
  }

  disconnect() {
    if (this._modal) {
      // Listener sauber entfernen
      if (this.boundSubmitEdit) {
        this._modalContent.removeEventListener("submit", this.boundSubmitEdit);
      }
      if (this.boundHandleModalClick) {
        this._modal.removeEventListener("click", this.boundHandleModalClick);
      }
      this._modal.remove();
    }
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
            <img src="${url}" class="kmm-preview-image" alt="Preview">
            
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

  // ####################################################################
  // ####################  EDIT / MODAL LOGIC  ##########################
  // ####################################################################
  // media_upload_controller.js

  async edit(event) {
    event.preventDefault();
    const button = event.currentTarget;
    const mediaId = button.dataset.mediaId;

    if (!mediaId) return;

    // 1. Basis URL (z.B. /media/95/edit)
    let url = this.editUrlTemplate.replace("ID_PLACEHOLDER", mediaId);

    // 2. Parameter vorbereiten (Cache-Buster + Locales)
    const params = new URLSearchParams();
    
    // Cache Buster (gegen das Browser-Caching Problem)
    params.append('t', Date.now());

    // NEU: Locales anhängen, falls im Dashboard konfiguriert
    // (z.B. wird daraus &locales=de,en)
    if (this.editableAltTextLocalesValue) {
        params.append('locales', this.editableAltTextLocalesValue);
    }

    // URL final zusammensetzen
    url += "?" + params.toString();

    // 3. UI: Modal öffnen und Spinner anzeigen
    this._modal.classList.remove("kmm-hidden");
    
    // Spinner (aus deinem CSS/SVG)
    this._modalContent.innerHTML = `<div style="display:flex; justify-content:center; padding:3rem;"><svg class="kmm-placeholder-spin" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5a.75.75 0 0 1 1.5 0c0 5.523-4.477 10-10 10S2 17.523 2 12S6.477 2 12 2a.75.75 0 0 1 0 1.5"/></svg></div>`;

    try {
      // 4. Fetch Request (GET)
      const response = await fetch(url, {
        method: "GET",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        cache: "no-cache", // Zwingt den Browser, den Server zu fragen
      });

      if (!response.ok) throw new Error("Fehler beim Laden");

      const html = await response.text();

      // 5. HTML einfügen
      // Der EventListener (submit), den wir in connect() an _modalContent gehängt haben,
      // greift hier automatisch für das neue Formular.
      this._modalContent.innerHTML = html;

      // 6. Fokus setzen (Usability)
      const input = this._modalContent.querySelector("input:not([type='hidden'])");
      if (input) input.focus();

    } catch (error) {
      console.error(error);
      this._modalContent.innerHTML = `<div style="color:red; text-align:center; padding:2rem;">Fehler beim Laden.</div>`;
    }
  }async edit(event) {
    event.preventDefault();
    const button = event.currentTarget;
    const mediaId = button.dataset.mediaId;

    if (!mediaId) return;

    // 1. Basis URL (z.B. /media/95/edit)
    let url = this.editUrlTemplate.replace("ID_PLACEHOLDER", mediaId);

    // 2. Parameter vorbereiten (Cache-Buster + Locales)
    const params = new URLSearchParams();
    
    // Cache Buster (gegen das Browser-Caching Problem)
    params.append('t', Date.now());

    // NEU: Locales anhängen, falls im Dashboard konfiguriert
    // (z.B. wird daraus &locales=de,en)
    if (this.editableAltTextLocalesValue) {
        params.append('locales', this.editableAltTextLocalesValue);
    }

    // URL final zusammensetzen
    url += "?" + params.toString();

    // 3. UI: Modal öffnen und Spinner anzeigen
    this._modal.classList.remove("kmm-hidden");
    
    // Spinner (aus deinem CSS/SVG)
    this._modalContent.innerHTML = `<div style="display:flex; justify-content:center; padding:3rem;"><svg class="kmm-placeholder-spin" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5a.75.75 0 0 1 1.5 0c0 5.523-4.477 10-10 10S2 17.523 2 12S6.477 2 12 2a.75.75 0 0 1 0 1.5"/></svg></div>`;

    try {
      // 4. Fetch Request (GET)
      const response = await fetch(url, {
        method: "GET",
        headers: { "X-Requested-With": "XMLHttpRequest" },
        cache: "no-cache", // Zwingt den Browser, den Server zu fragen
      });

      if (!response.ok) throw new Error("Fehler beim Laden");

      const html = await response.text();

      // 5. HTML einfügen
      // Der EventListener (submit), den wir in connect() an _modalContent gehängt haben,
      // greift hier automatisch für das neue Formular.
      this._modalContent.innerHTML = html;

      // 6. Fokus setzen (Usability)
      const input = this._modalContent.querySelector("input:not([type='hidden'])");
      if (input) input.focus();

    } catch (error) {
      console.error(error);
      this._modalContent.innerHTML = `<div style="color:red; text-align:center; padding:2rem;">Fehler beim Laden.</div>`;
    }
  }

  // --- SUBMIT LOGIC (Bleibt gleich, wird aber jetzt garantiert aufgerufen) ---
  async submitEdit(event) {
    // 1. Browser stoppen (Kein Redirect, Kein JSON im Fenster!)
    event.preventDefault();
    event.stopPropagation();

    const form = event.target;

    // Button Feedback
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn ? submitBtn.innerHTML : "";
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

      const contentType = response.headers.get("content-type");

      // SUCCESS: JSON
      if (
        response.ok &&
        contentType &&
        contentType.includes("application/json")
      ) {
        const data = await response.json();

        // Update DOM & Modal schließen
        this.updateItemInDOM(data);
        this.closeModal();
      }
      // ERROR: HTML (Formular Validierungsfehler)
      else {
        const html = await response.text();
        this._modalContent.innerHTML = html;
        // Der Listener aus connect() bleibt aktiv, also funktioniert der nächste Versuch auch!
      }
    } catch (error) {
      console.error("Save failed", error);
      if (submitBtn) submitBtn.innerText = "Fehler";
    } finally {
      // Button Reset
      if (
        submitBtn &&
        !this._modal.classList.contains("kmm-hidden") &&
        !submitBtn.innerText.includes("Fehler")
      ) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    }
  }

  updateItemInDOM(data) {
    if (!data.id) return;
    // Wir suchen das Item in der previewListTarget (die ist NICHT teleportiert, also finden wir sie)
    const item = this.previewListTarget.querySelector(
      `.kmm-photo-item[data-media-id="${data.id}"]`,
    );
    if (!item) return;

    const img = item.querySelector(".kmm-preview-image");
    if (img && data.alt !== undefined) {
      img.alt = data.alt;
    }

    // Feedback
    item.style.transition = "box-shadow 0.3s";
    item.style.boxShadow = "0 0 0 4px #22c55e";
    setTimeout(() => {
      item.style.boxShadow = "";
    }, 1000);
  }

  handleModalClick(event) {
    // Wir prüfen: Wurde etwas geklickt, das schließen soll?

    // 1. Prüfen auf data-action="...closeModal" (X-Button & Abbrechen-Button)
    // .closest() sucht vom geklickten Element nach oben (wichtig beim SVG Icon im Button)
    const closeTrigger = event.target.closest('[data-action*="closeModal"]');

    // 2. Prüfen auf Backdrop (Klick neben das Modal)
    const isBackdrop = event.target.classList.contains("kmm-modal-backdrop");

    if (closeTrigger || isBackdrop) {
      this.closeModal(event);
    }
  }

  // --- SCHLIEẞEN LOGIK ---
  closeModal(event) {
    if (event) event.preventDefault();

    if (this._modal) {
      // Modal verstecken
      this._modal.classList.add("kmm-hidden");
      // Inhalt leeren (spart Speicher und verhindert ID-Konflikte)
      this._modalContent.innerHTML = "";
    }
  }

  // B. Badge Logik (Beispiel: Wenn Alt Text da ist, zeige Badge)
  // Da dein Template String in JS ist, musst du schauen, ob du dort eine Klasse/Element dafür vorgesehen hast.
  // Wenn nicht, kannst du es hier via JS injecten.

  /* Beispiel:
        let badge = item.querySelector('.kmm-alt-badge');
        if (data.alt && !badge) {
            // Badge erstellen wenn noch nicht da
            badge = document.createElement('span');
            badge.className = 'kmm-alt-badge'; // CSS Klasse aus media.css
            badge.innerText = 'ALT';
            item.appendChild(badge);
        } else if (!data.alt && badge) {
            // Badge entfernen wenn Alt-Text gelöscht wurde
            badge.remove();
        }
        */
}
