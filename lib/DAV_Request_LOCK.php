<?php

/*·************************************************************************
 * Copyright ©2007-2011 Pieter van Beek, Almere, The Netherlands
 * 		    <http://purl.org/net/6086052759deb18f4c0c9fb2c3d3e83e>
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at <http://www.apache.org/licenses/LICENSE-2.0>
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * $Id: dav_request_lock.php 3364 2011-08-04 14:11:03Z pieterb $
 **************************************************************************/

/**
 * File documentation (who cares)
 * @package DAV
 */

/**
 * Helper class for parsing LOCK request bodies.
 * @internal
 * @package DAV
 */
class DAV_Request_LOCK extends DAV_Request {


/**
 * @var string XML fragment
 */
public $owner = null;


/**
 * @var array an array of timeouts requested by the client, in order.
 */
public $timeout = array();


/**
 * @var bool indicates if the client requested a new lock, or a refresh.
 */
public $newlock = false;


/**
 * Handles the timeout header
 * @return  void
 * @throws  DAV_Status  When a timeout has expired
 */
private function init_timeout() {
  // Parse the Timeout: request header:
  if ( !isset( $_SERVER['HTTP_TIMEOUT'] ) ) return;
  $timeouts = preg_split( '/,\\s*/', $_SERVER['HTTP_TIMEOUT'] );
  foreach ($timeouts as $timeout) {
    if ( !preg_match( '@^\\s*(?:Second-(\\d+)|(Infinite))\\s*$@', $timeout, $matches ) )
      throw new DAV_Status(
        DAV::HTTP_BAD_REQUEST,
        "Couldn't parse HTTP header Timeout: " . $_SERVER['HTTP_TIMEOUT']
      );
    if ( (int)$matches[1] > 0 )
      $this->timeout[] = (int)$matches[1];
    elseif ( !empty( $matches[2] ) )
      $this->timeout[] = 0;
    else
      throw new DAV_Status(
        DAV::HTTP_BAD_REQUEST,
        "Couldn't parse HTTP header Timeout: " . $_SERVER['HTTP_TIMEOUT']
      );
  }
}


/**
 * Enter description here...
 *
 * @throws DAV_Status
 */
protected function __construct()
{
  parent::__construct();
  $this->init_timeout();

  $input = $this->inputstring();
  if (empty($input)) return;

  // New lock!
  $this->newlock = true;

  $document = new DOMDocument();
  if ( preg_match( '/xmlns:[a-zA-Z0-9]*=""/', $input ) ||
       ! @$document->loadXML(
          $input,
          LIBXML_NOCDATA | LIBXML_NOENT | LIBXML_NSCLEAN | LIBXML_NOWARNING | LIBXML_NOERROR
        ) )
  {
    throw new DAV_Status(
      DAV::HTTP_BAD_REQUEST, 'Request body is not well-formed XML.'
    );
  }

  $xpath = new DOMXPath($document);
  $xpath->registerNamespace('D', 'DAV:');

  if ( (int)($xpath->evaluate('count(/D:lockinfo/D:lockscope/D:shared)')) > 0 )
    throw new DAV_Status(DAV::HTTP_NOT_IMPLEMENTED, 'Shared locks are not supported.');
  elseif ( (int)($xpath->evaluate('count(/D:lockinfo/D:lockscope/D:exclusive)')) !== 1 ) {
    throw new DAV_Status(DAV::HTTP_BAD_REQUEST, 'No &lt;lockscope/&gt; element in LOCK request, hmm.');
  }

  if ( (int)($xpath->evaluate('count(/D:lockinfo/D:locktype/D:write)')) !== 1 )
    throw new DAV_Status(
      DAV::HTTP_UNPROCESSABLE_ENTITY, 'Unknown lock type in request body'
    );

  $ownerlist = $xpath->query('/D:lockinfo/D:owner');
  if ($ownerlist->length) {
    $ownerxml = '';
    $ownerchildnodes = $ownerlist->item(0)->childNodes;
    for ($i = 0; $child = $ownerchildnodes->item($i); $i++)
      $ownerxml .= DAV::recursiveSerialize($child);
    $this->owner = trim($ownerxml);
  }
}


/**
 * Returns the depth header or the default of no depth header is supplied
 * 
 * @return  string  The value to be used as the value of the depth header
 */
public function depth() {
  $retval = parent::depth();
  return is_null($retval) ? DAV::DEPTH_INF : $retval;
}


/**
 * Handles the LOCK request
 * 
 * @param DAV_Resource $resource
 * @return void
 * @throws DAV_Status
 */
protected function handle( $resource ) {
  if (!DAV::$LOCKPROVIDER)
    throw new DAV_Status(DAV::HTTP_NOT_IMPLEMENTED);
  return $this->newlock ?
    $this->handleCreateLock($resource) :
    $this->handleRefreshLock($resource);
}


//private function respond($lock_token = null) {
//  $lock = DAV::$LOCKPROVIDER->getlock(DAV::getPath());
//  $headers = array( 'Content-Type' => 'application/xml; charset="utf-8"' );
//  if ($lock_token) $headers['Lock-Token'] = "<{$token}>";
//  DAV::header($headers);
//  echo DAV::xml_header() . '<D:prop xmlns:D="DAV:"><D:lockdiscovery>' .
//    $lock->toXML() . '</D:lockdiscovery></D:prop>';
//}



/**
 * Handles the creation of a lock
 * 
 * @param DAV_Resource $resource
 * @return void
 * @throws DAV_Status
 */
private function handleCreateLock($resource) {
  // Check conflicting (parent) locks:
  if ( ( $lock = DAV::$LOCKPROVIDER->getlock( DAV::getPath() ) ) )
    throw new DAV_Status(
      DAV::HTTP_LOCKED,
      array( DAV::COND_NO_CONFLICTING_LOCK => new DAV_Element_href( $lock->lockroot ) )
    );

  // Find out the depth:
  $depth = $this->depth();
  if (DAV::DEPTH_1 === $depth)
    throw new DAV_Status(
      DAV::HTTP_BAD_REQUEST,
      'Depth: 1 is not supported for method LOCK.'
    );


  $headers = array( 'Content-Type' => 'application/xml; charset="utf-8"' );
  if ( !$resource ) {
    // Check unmapped collection resource:
    if ( substr( DAV::getPath(), -1 ) === '/' )
      throw new DAV_Status(
        DAV::HTTP_NOT_FOUND,
        'Unmapped collection resource'
      );
    $parent = DAV::$REGISTRY->resource(dirname(DAV::getPath()));
    if (!$parent || !$parent->isVisible())
      throw new DAV_Status(DAV::HTTP_CONFLICT, 'Unable to LOCK unexisting parent collection');
    $parent->assertLock();
    $resource = $parent->create_member(basename(DAV::getPath()));

    if ( false !== strpos($_SERVER['HTTP_USER_AGENT'], 'Microsoft') ) {
      // For M$, we need to mimic RFC2518:
      $headers['status'] = DAV::HTTP_OK;
    } else {
      $headers['status'] = DAV::HTTP_CREATED;
      $headers['Location'] = DAV::encodeURIFullPath( DAV::getPath() );
    }
  }

  if ( $resource instanceof DAV_Collection &&
       $depth === DAV::DEPTH_INF &&
       ( $memberLocks = DAV::$LOCKPROVIDER->memberLocks( DAV::getPath() ) ) ) {
    $memberLockPaths = array();
    foreach ($memberLocks as $memberLock)
      $memberLockPaths[] = $memberLock->lockroot;
    throw new DAV_Status(
      DAV::HTTP_LOCKED, array(
        DAV::COND_NO_CONFLICTING_LOCK => new DAV_Element_href($memberLockPaths)
      )
    );
  }

  $token = DAV::$LOCKPROVIDER->setlock(
    DAV::getPath(), $depth, $this->owner, $this->timeout
  );
  DAV::$SUBMITTEDTOKENS[$token] = $token;
  $headers['Lock-Token'] = "<{$token}>";

  if ( !( $lockdiscovery = $resource->prop_lockdiscovery() ) )
    throw new DAV_Status( DAV::HTTP_INTERNAL_SERVER_ERROR );

  // Generate output:
  DAV::header($headers);
  echo DAV::xml_header() . '<D:prop xmlns:D="DAV:"><D:lockdiscovery>' .
    $lockdiscovery . '</D:lockdiscovery></D:prop>';
}


/**
 * Refreshes an already existing lock
 * 
 * @param DAV_Resource $resource
 * @return void
 * @throws DAV_Statuss
 */
private function handleRefreshLock($resource) {
  $if_header = $this->if_header;
  if ( !isset( $if_header[DAV::getPath()] ) ||
       !$if_header[DAV::getPath()]['lock'] )
    throw new DAV_Status(
      DAV::HTTP_BAD_REQUEST, array(
        DAV::COND_LOCK_TOKEN_SUBMITTED => new DAV_Element_href( DAV::getPath() )
      )
    );
  // I think this can never evaluate to true, because DAV_Request already checks
  // whether the 'If' header matches the lock token of the resource. So if the
  // resource doesn't have a lock, this is already detected before this method
  // is called! (However, I don't dare to delete this yet and it doesn't hurt to
  // keep it)
  if ( !( $lock = DAV::$LOCKPROVIDER->getlock(DAV::getPath()) ) )
    throw new DAV_Status(
      DAV::HTTP_PRECONDITION_FAILED,
      array(DAV::COND_LOCK_TOKEN_MATCHES_REQUEST_URI)
    );
  DAV::$LOCKPROVIDER->refresh(
    $lock->lockroot,
    $lock->locktoken,
    $this->timeout
  );

  if ( !( $lockdiscovery = $resource->prop_lockdiscovery() ) )
    throw new DAV_Status( DAV::HTTP_INTERNAL_SERVER_ERROR );

  // Generate output:
  DAV::header('application/xml; charset="utf-8"');
  echo DAV::xml_header() . '<D:prop xmlns:D="DAV:"><D:lockdiscovery>' .
    $lockdiscovery . '</D:lockdiscovery></D:prop>';
}


} // class DAV_Request_LOCK


