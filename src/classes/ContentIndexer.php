<?php

namespace Bluebranch\Chatbot\classes;

use Contao\ContentElement;

class ContentIndexer extends ContentElement
{
    /**
     * Template
     * @var string
     */
    protected $strTemplate;

    /**
     * Generate the content element
     */
    public function generate()
    {
        if (\Contao\System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest(\Contao\System::getContainer()->get('request_stack')->getCurrentRequest() ?? \Symfony\Component\HttpFoundation\Request::create(''))) {
            $objTemplate = new \Contao\BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### ' . strtoupper($GLOBALS['TL_LANG']['CTE'][$this->type][0] ?? $this->type) . ' ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = 'contao?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

            return $objTemplate->parse();
        }

        return parent::generate();
    }

    /**
     * Generate the content element
     */
    protected function compile()
    {
        // Elements only provide a template that contains the indexer markers
        $this->strTemplate = 'ce_' . $this->type;

        if ($this->type === 'indexer_stop') {
            $this->wrapper = [true, false];
        } elseif ($this->type === 'indexer_continue') {
            $this->wrapper = [false, true];
        }
    }
}
