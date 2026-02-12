<?php

namespace Kmergen\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Kmergen\MediaBundle\Repository\MediaRepository;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255)]
    private ?string $mime = null;

    #[ORM\Column]
    private ?int $size = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $dimension = null;

    #[ORM\ManyToOne(inversedBy: 'media')]
    private ?MediaAlbum $album = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tempKey = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $alt = []; // Nullable ?array erlaubt, aber Init als []

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getMime(): ?string
    {
        return $this->mime;
    }

    public function setMime(string $mime): static
    {
        $this->mime = $mime;
        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): void
    {
        $this->position = $position;
    }

    public function getDimension(): ?string
    {
        return $this->dimension;
    }

    public function setDimension(?string $dimension): static
    {
        $this->dimension = $dimension;
        return $this;
    }

    public function getAlbum(): ?MediaAlbum
    {
        return $this->album;
    }

    public function setAlbum(?MediaAlbum $album): static
    {
        $this->album = $album;
        return $this;
    }

    public function getTempKey(): ?string
    {
        return $this->tempKey;
    }

    public function setTempKey(?string $tempKey): static
    {
        $this->tempKey = $tempKey;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

    // --- ALT TEXT LOGIK ---

    /**
     * Getter:
     * - Ohne Parameter: Gibt Array zurück
     * - Mit Locale: Gibt String zurück (oder null)
     */
    public function getAlt(?string $locale = null): string|array|null
    {
        if ($this->alt === null) {
            return $locale ? null : [];
        }

        if ($locale) {
            return $this->alt[$locale] ?? null;
        }
        return $this->alt;
    }

    /**
     * Standard Setter (Doctrine)
     */
    public function setAlt(?array $alt): self
    {
        $this->alt = $alt ?? [];
        return $this;
    }

    /**
     * Helper: Setzt einen einzelnen Wert für eine Sprache.
     * Wird vom FormType aufgerufen.
     */
    public function setAltForLocale(string $locale, ?string $value): self
    {
        // Sicherheit: Array initialisieren falls null
        $this->alt ??= [];

        if (empty($value)) {
            unset($this->alt[$locale]);
        } else {
            $this->alt[$locale] = $value;
        }
        return $this;
    }
}