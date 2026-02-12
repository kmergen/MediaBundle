<?php

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
        // Das Array der zu bearbeitenden Sprachen (z.B. ['de'] oder ['de', 'en', 'fr'])
        $locales = $options['locales'];

        foreach ($locales as $locale) {
            $fieldName = 'alt_' . $locale; // Eindeutiger Name: alt_de, alt_en ...

            $builder->add($fieldName, TextType::class, [
                'mapped' => false,
                'label' => 'Beschreibung (' . strtoupper($locale) . ')',
                'required' => false,
                'attr' => [
                    'class' => 'kmm-form-input',
                    'placeholder' => $locale === 'de' ? 'Inhalt des Bildes beschreiben...' : 'Describe image content...',
                    // Nur beim ersten Feld Enter-Submit erlauben, oder bei allen? 
                    // Bei allen ist ok:
                    'data-action' => 'keydown.enter->media-upload#submitEdit'
                ],
                'label_attr' => ['class' => 'kmm-form-label'],
            ]);
        }

        // 1. DATEN LADEN
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($locales) {
            /** @var Media|null $media */
            $media = $event->getData();
            $form = $event->getForm();

            if ($media) {
                foreach ($locales as $locale) {
                    $fieldName = 'alt_' . $locale;
                    // Daten aus der Entity holen
                    $val = $media->getAlt($locale);
                    // Ins Feld schreiben
                    $form->get($fieldName)->setData($val);
                }
            }
        });

        // 2. DATEN SPEICHERN
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($locales) {
            /** @var Media $media */
            $media = $event->getData();
            $form = $event->getForm();

            foreach ($locales as $locale) {
                $fieldName = 'alt_' . $locale;
                $newText = $form->get($fieldName)->getData();

                // In Entity speichern
                $media->setAltForLocale($locale, $newText);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Media::class,
            'current_locale' => 'de',
            'locales' => ['de'], // Default: Nur Deutsch
        ]);

        // Erlaubte Typen definieren
        $resolver->setAllowedTypes('locales', 'array');
    }
}
