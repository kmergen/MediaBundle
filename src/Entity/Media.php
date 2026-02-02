<?php

namespace Kmergen\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Kmergen\MediaBundle\Repository\MediaRepository;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
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

    public function tempKey(): ?bool
    {
        return $this->tempKey;
    }

    public function setTempKey(?string $tempKey): static
    {
        $this->tempKey = $tempKey;

        return $this;
    }
}
