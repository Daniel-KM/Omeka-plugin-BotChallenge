<?php

/**
 * Build the link preview of a page for a bot, without dispatching the page.
 *
 * The user agent of a preview bot is declarative, so the preview must cost as
 * little as the challenge itself: it reads the record only (item, file,
 * collection, simple page), never runs a search, a controller or the theme.
 * Without a record, the card of the site is returned.
 */
class BotChallenge_LinkPreview
{
    /**
     * Controllers of the records mapped to their model.
     */
    const RECORDS = array(
        'items' => 'Item',
        'files' => 'File',
        'collections' => 'Collection',
    );

    /**
     * @return array Http status code and html of the preview.
     */
    public function render(Zend_Controller_Request_Http $request): array
    {
        $url = $request->getScheme() . '://' . $request->getHttpHost() . $request->getRequestUri();
        $siteTitle = (string) get_option('site_title');

        $status = 200;
        $title = $siteTitle;
        $description = (string) get_option('description');
        $image = '';

        $module = (string) $request->getModuleName();
        $controller = (string) $request->getControllerName();
        $id = (int) $request->getParam('id');
        if ($request->getActionName() === 'show' && $id) {
            $record = null;
            if (in_array($module, array('', 'default'), true) && isset(self::RECORDS[$controller])) {
                $record = $this->publicRecord(self::RECORDS[$controller], $id);
            } elseif ($module === 'simple-pages' && class_exists('SimplePagesPage')) {
                $record = get_db()->getTable('SimplePagesPage')->find($id);
                $record = $record && $record->is_published ? $record : null;
            }
            if (!$record) {
                $status = 404;
            } elseif ($record instanceof SimplePagesPage) {
                $title = (string) $record->title;
                $description = '';
            } else {
                $title = $this->elementText($record, 'Title')
                    ?: ($record instanceof File ? (string) $record->original_filename : $siteTitle);
                $description = $this->elementText($record, 'Description');
                $file = $record instanceof Item ? $record->getFile(0) : ($record instanceof File ? $record : null);
                if ($file && $file->hasThumbnail()) {
                    $image = (string) $file->getWebPath('fullsize');
                }
            }
        }

        $description = mb_substr(trim((string) preg_replace('~\s+~u', ' ', strip_tags($description))), 0, 300);
        $title = trim(strip_tags($title)) ?: $siteTitle;

        $meta = array(
            array('property', 'og:type', 'website'),
            array('property', 'og:site_name', $siteTitle),
            array('property', 'og:title', $title),
            array('property', 'og:url', $url),
            array('property', 'og:description', $description),
            array('name', 'description', $description),
            array('property', 'og:image', $image),
            array('name', 'twitter:card', $image ? 'summary_large_image' : 'summary'),
        );

        $e = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        };
        $html = "<!DOCTYPE html>\n<html>\n<head>\n    <meta charset=\"utf-8\">\n    <title>" . $e($title) . "</title>\n";
        foreach ($meta as $item) {
            if ($item[2] !== '') {
                $html .= '    <meta ' . $item[0] . '="' . $e($item[1]) . '" content="' . $e($item[2]) . "\">\n";
            }
        }
        $html .= '    <link rel="canonical" href="' . $e($url) . "\">\n</head>\n<body>\n"
            . '    <p><a href="' . $e($url) . '">' . $e($title) . "</a></p>\n</body>\n</html>\n";

        return array($status, $html);
    }

    /**
     * Get a record only when it is public, since the visitor is anonymous.
     */
    protected function publicRecord(string $model, int $id): ?Omeka_Record_AbstractRecord
    {
        $record = get_db()->getTable($model)->find($id);
        if (!$record) {
            return null;
        }
        $item = $record instanceof File ? $record->getItem() : $record;
        return $item && !empty($item->public) ? $record : null;
    }

    protected function elementText(Omeka_Record_AbstractRecord $record, string $element): string
    {
        $texts = $record->getElementTexts('Dublin Core', $element);
        return $texts ? (string) $texts[0]->text : '';
    }
}
