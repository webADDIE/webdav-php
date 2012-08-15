<?php

/*·************************************************************************
 * Copyright ©2007-2012 Pieter van Beek, Almere, The Netherlands
 *           <http://purl.org/net/6086052759deb18f4c0c9fb2c3d3e83e>
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
 **************************************************************************/

/**
 * File documentation (who cares)
 * @package DAVLock
 */

/**
 * Resource to be disclosed through WebDAV
 * @package DAVLock
 */

class DAVLock_Resource {


/**
 * @return string XML
 */
final public function prop_lockdiscovery() {
  if ( ! DAV::$LOCKPROVIDER ) return null;
  $retval = ( $lock = DAV::$LOCKPROVIDER->getlock($this->path) ) ?
    $lock->toXML() : '';
  return $retval;
}


/**
 * @return string XML
 */
final public function prop_supportedlock() {
  if ( ! DAV::$LOCKPROVIDER ) return null;
  return <<<EOS
<D:lockentry>
  <D:lockscope><D:exclusive/></D:lockscope>
  <D:locktype><D:write/></D:locktype>
</D:lockentry>
EOS;
}



} // class DAV_Resource

