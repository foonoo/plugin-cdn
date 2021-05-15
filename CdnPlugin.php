<?php

namespace foonoo\plugins\contrib\cdn;

use foonoo\content\Content;
use foonoo\events\ContentGenerationStarted;
use foonoo\events\ContentOutputGenerated;
use foonoo\Plugin;

/**
 * Class CdnPlugin
 */
class CdnPlugin extends Plugin
{
    /**
     * @var Content
     */
    private $activeContent;

    /**
     * @return \Closure[]
     */
    public function getEvents()
    {
        return [
            ContentOutputGenerated::class => function (ContentOutputGenerated $event) { $this->processMarkup($event); },
            ContentGenerationStarted::class => function (ContentGenerationStarted $event) {
                $this->activeContent = $event->getContent();
            }
        ];
    }

    private function processImg(\DOMElement $tag) {
        $src = $tag->getAttribute("src");
        var_dump($src, $this->activeContent->getDestination());
    }

    private function processMarkup(ContentOutputGenerated  $event)
    {
        if(!$event->hasDOM()) {
            return;
        }

        $xpath = new \DOMXPath($event->getDOM());
        $supportedTags = ['img' => [$this, 'processImg']];
        foreach($supportedTags as $tag => $processor) {
            $tags = $xpath->query("//$tag");
            foreach($tags as $tag) {
                $this->processImg($tag);
            }
        }
    }
}