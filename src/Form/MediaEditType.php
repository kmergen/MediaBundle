<?php
// src/Form/MediaEditType.php

namespace Kmergen\MediaBundle\Form;

use Kmergen\MediaBundle\Entity\Media;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MediaEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locales = $options['locales'];

        foreach ($locales as $locale) {
            $fieldName = 'alt_' . $locale;

            $builder->add($fieldName, TextType::class, [
                'mapped' => false,
                // Wir nutzen einen Key. Das %locale% ersetzen wir später im Twig.
                'label' => 'form.media_edit.label',
                'required' => false,
                'attr' => [
                    'class' => 'kmm-form-input',
                    // Auch hier ein Key für den Platzhalter
                    'placeholder' => 'form.media_edit.placeholder',
                    'data-action' => 'keydown.enter->media-upload#submitEdit'
                ],
                'label_attr' => ['class' => 'kmm-form-label'],
                // Wichtig: Wir sagen dem Formular, welche Domain es nutzen soll
                'translation_domain' => 'KmMedia',
            ]);
        }

        // 1. DATEN LADEN (PRE_SET_DATA) - bleibt identisch
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($locales) {
            $media = $event->getData();
            $form = $event->getForm();

            if ($media instanceof Media) {
                foreach ($locales as $locale) {
                    $form->get('alt_' . $locale)->setData($media->getAlt($locale));
                }
            }
        });

        // 2. DATEN SPEICHERN (POST_SUBMIT) - bleibt identisch
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($locales) {
            $media = $event->getData();
            $form = $event->getForm();

            foreach ($locales as $locale) {
                $newText = $form->get('alt_' . $locale)->getData();
                $media->setAltForLocale($locale, $newText);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Media::class,
            'current_locale' => 'de',
            'locales' => ['de'],
            'translation_domain' => 'KmMedia', // Global für diesen Typ setzen
        ]);

        $resolver->setAllowedTypes('locales', 'array');
    }
}
