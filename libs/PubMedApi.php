<?php
/**
 * PubMedApi
 * 
 */

include('Http.php');

class PubMedApi
{
    public $term = '';
    public $db = 'pubmed';
    public $retmode = 'xml';
    public $retmax = 10;                    // Max number of results to return
    public $retstart = 0;                   // The search result number to start displaying data
    public $count = 0;                      // Sets to the number of search results
    public $exact_match = true;             // Exact match narrows the search results by wrapping in quotes

    public $use_cache = false;              // Save JSON formatted search results to a text file if TRUE
    public $cache_dir = '';                 // Directory where cached results will be saved
    public $cache_life = 604800;            // Caching time, in seconds, default 7 days
    public $cache_file_hash = '';           // Sets to the md5 hash of the search term

    private $curl_timeout = 15;
    private $curl_site_url = '';

    private $esearch = 'http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?';
    private $efetch = 'http://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?';
    private $elink = 'http://eutils.ncbi.nlm.nih.gov/entrez/eutils/elink.fcgi?';

    function __construct($options=array())
    {
        foreach($options as $attribute => $option) {
            $this->$attribute = $option;
        }
        return $this;
    }

    public static function request($query = null, $author = null, $exclusions = null, $page = 1)
    {
        $pubmed = new static(array('exact_match' => false));
        $pubmed->retstart = ($page - 1) * $pubmed->retmax;
        $pubmed->term = $pubmed->buildTerm($query, $author, $exclusions);

        $esearchResponse = $pubmed->getEsearchXmlByTerm();
        if (!$esearchResponse)
            return Http::sendJsonResponse(500, $this->error);

        $pubmed->count = $pubmed->parseCount($esearchResponse);
        $pubmed->pmids = $pubmed->parsePmids($esearchResponse);

        $efetchResponse = $pubmed->getEfetchXmlByPmids();
        if (!$efetchResponse)
            return Http::sendJsonResponse(500, $this->error);

        $results = $pubmed->parseEfetchXml($efetchResponse);

        if ($pubmed->use_cache)
            $pubmed->cacheResults($results);

        return $results;
    }

    public function requestEfetchData($pmid)
    {
        $this->pmids = $pmid;
        $efetchResponse = $this->getEfetchXmlByPmids();
        if (!$efetchResponse)
            return Http::sendJsonResponse(500, $this->error);        
        return $this->parseEfetchXml($efetchResponse);
    }

    public function requestElinkData($pmid)
    {
        $this->pmids = $pmid;
        $elinkResponse = $this->getElinkXmlByPmids();
        if (!$elinkResponse)
            return Http::sendJsonResponse(500, $this->error);        
        return $this->parseElinkXml($elinkResponse);
    }

    protected function buildTerm($query, $author, $exclusions)
    {
        $term = '';

        if ($query !== null) {
            $term = $query . '[All Fields]';

            if ($author !== null)
                $term .= ' AND ';
        }

        if ($author !== null)
            $term .= $author . '[Full Author Name]';

        if ($exclusions !== null) {
            $term .= ' NOT ';

            if (!is_array($exclusions))
                $exclusions = (array)$exclusions;

            foreach ($exclusions as &$exclusion)
                $exclusion .= '[uid]';

            if (count($exclusions) === 1)
                $term .= $exclusions[0];
            else
                $term .= '(' . implode(' OR ', $exclusions) . ')';
        }
        return $term;
    }

    protected function getEsearchXmlByTerm()
    {
        if ($this->use_cache)
            return $this->getPmidsFromCache($this->term);

        if ($this->exact_match)
            $this->term = urlencode(sprintf('"%s"',$this->term));
        else
            $this->term = urlencode(trim($this->term));

        return $this->queryUrl($this->buildESearchUrl());
    }

    protected function getEfetchXmlByPmids()
    {
        $pmids = implode(',', $this->pmids);
        return $this->queryUrl($this->buildEFetchUrl($pmids));
    }

    protected function getElinkXmlByPmids()
    {
        $pmids = implode(',', $this->pmids);
        return $this->queryUrl($this->buildELinkUrl($pmids));
    }

    protected function cacheResults($results)
    {
        if ($this->term == '')
            return;

        $this->cache_file_hash = md5($this->term);

        $fh = @fopen($this->cache_dir.'cache_'.$this->cache_file_hash.'_'.$this->retstart.'.json', 'wb');
        if (!$fh)
            die('Unable to write cache file to cache directory. \''.$this->cache_dir.'\'.');

        $jsonData = array(
            'results' => $results,
            'term' => addslashes($this->term),
            'count' => $this->count,
            'retstart' => $this->retstart
        );

        fwrite($fh, json_encode($jsonData));
        fclose($fh);
    }

    protected function parseCount($xml)
    {
        return (int)$xml->Count;
    }

    protected function parsePmids($xml)
    {
        $pmids = array();
            if (isset($xml->IdList->Id) && !empty($xml->IdList->Id))
                foreach ($xml->IdList->children() as $id)
                    $pmids[] = (string)$id;
        return $pmids;
    }

    protected function parseEFetchXml($xml)
    {
        $data = array();
        foreach ($xml->PubmedArticle as $art) {

            $authors = array();
            if (isset($art->MedlineCitation->Article->AuthorList->Author)) {
                try {
                    foreach ($art->MedlineCitation->Article->AuthorList->Author as $k => $a) {
                        $authors[] = (string)$a->LastName .' '. (string)$a->Initials;
                    }
                } catch (Exception $e) {
                    $a = $art->MedlineCitation->Article->AuthorList->Author;
                    $authors[] = (string)$a->LastName .' '. (string)$a->Initials;
                }
            }

            $keywords = array();
            if (isset($art->MedlineCitation->MeshHeadingList->MeshHeading)) {
                foreach ($art->MedlineCitation->MeshHeadingList->MeshHeading as $k => $m) {
                    $keywords[] = (string)$m->DescriptorName;
                    if (isset($m->QualifierName)) {
                        if (is_array($m->QualifierName)) {
                            $keywords = array_merge($keywords,$m->QualifierName);
                        } else {
                            $keywords[] = (string)$m->QualifierName;
                        }
                    }
                }
            }

            $articleid = array();
            if (isset($art->PubmedData->ArticleIdList)) {
                foreach ($art->PubmedData->ArticleIdList->ArticleId as $id) {
                    $articleid[] = $id;
                }
            }

            $data[] = array(
                'pmid'			=> (string) $art->MedlineCitation->PMID,
                'volume'		=> (string) $art->MedlineCitation->Article->Journal->JournalIssue->Volume,
                'issue'			=> (string) $art->MedlineCitation->Article->Journal->JournalIssue->Issue,
                'year'			=> (string) $art->MedlineCitation->Article->Journal->JournalIssue->PubDate->Year,
                'month'			=> (string) $art->MedlineCitation->Article->Journal->JournalIssue->PubDate->Month,
                'day'			=> (string) $art->MedlineCitation->Article->Journal->JournalIssue->PubDate->Day,
                'pages'			=> (string) $art->MedlineCitation->Article->Pagination->MedlinePgn,
                'issn'			=> (string) $art->MedlineCitation->Article->Journal->ISSN,
                'journal'		=> (string) $art->MedlineCitation->Article->Journal->Title,
                'journalabbrev'	=> (string) $art->MedlineCitation->Article->Journal->ISOAbbreviation,
                'title'			=> (string) $art->MedlineCitation->Article->ArticleTitle,
                'abstract'		=> (string) $art->MedlineCitation->Article->Abstract->AbstractText,
                'affiliation'	=> (string) $art->MedlineCitation->Article->Affiliation,
                'authors'		=> $authors,
                'articleid'		=> implode(',',$articleid),
                'keywords'		=> $keywords
            );
        }
        return $data;
    }

    protected function parseELinkXml($xml)
    {
        $data = array();
        if (isset($xml->eLinkResult->LinkSet->LinkSet->IdUrlSet))
            foreach ($xml->eLinkResult->LinkSet->LinkSet->IdUrlSet as $link)
                $data[] = array(
                    'url'			=> (string) $link->ObjUrl->Url,
                    'iconurl'		=> (string) $link->ObjUrl->IconUrl,
                    'provider'		=> (string) $link->ObjUrl->Provider->Name
                );
        return $data;
    }

    private function getPmidsFromCache($term)
    {
        $this->cache_file_hash = md5($term);
        $cache_file = $this->cache_dir.'cache_'.$this->cache_file_hash.'_'.$this->retstart.'.json';
        $filemtime = @filemtime($cache_file);

        if (file_exists($cache_file) && (!$filemtime || (time() - $filemtime <= $this->cache_life))) {
            $data = json_decode(@file_get_contents($cache_file),true);
            $this->count = $data['count'];
            $this->retstart = $data['retstart'];
            return $data['results'];
        }
        return array();
    }

    private function queryUrl($url)
    {		
        ini_set('user_agent', $_SERVER['HTTP_USER_AGENT']);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_REFERER, $this->curl_site_url);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->curl_timeout);
        $response = curl_exec($curl);
        $curl_errno = curl_errno($curl);
        $curl_error = curl_error($curl);
        curl_close($curl);

        if ($curl_errno > 0 && $this->error = $curl_error)
            return false;

        $xml = simplexml_load_string($response);
        if (!$xml && $this->error = libxml_get_errors())
            return false;

        return $xml;
    }

    private function buildESearchUrl()
    {
        $params = array(
            '0' => 'db='.$this->db,
            '1' => 'retmax='.$this->retmax,
            '2' => 'retstart='.$this->retstart,
            '3' => 'term='.stripslashes($this->term)
        );

        return $this->esearch . implode('&',$params);
    }

    private function buildEFetchUrl($pmid)
    {
        $params = array(
            '0' => 'db='.$this->db,
            '1' => 'retmax='.$this->retmax,
            '2' => 'retmode='.$this->retmode,
            '3' => 'id='.(string)$pmid
        );
        return $this->efetch . implode('&', $params);
    }

    private function buildELinkUrl($pmid)
    {
        $params = array(
            '0' => 'dbfrom='.$this->db,
            '1' => 'id='.(string)$pmid,
            '2' => 'cmd='.'prlinks'
        );
        return $this->elink . implode('&', $params);;
    }
}
?>
