<?php
/**
 * This file implements the Filemanager class. {{{
 *
 * This file is part of the b2evolution/evocms project - {@link http://b2evolution.net/}.
 * See also {@link http://sourceforge.net/projects/evocms/}.
 *
 * @copyright (c)2003-2004 by Francois PLANQUE - {@link http://fplanque.net/}.
 * Parts of this file are copyright (c)2004 by Daniel HAHLER - {@link http://thequod.de/contact}.
 *
 * @license http://b2evolution.net/about/license.html GNU General Public License (GPL)
 * {@internal
 * b2evolution is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * b2evolution is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with b2evolution; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 * }}
 *
 * {@internal
 * Daniel HAHLER grants Fran�ois PLANQUE the right to license
 * Daniel HAHLER's contributions to this file and the b2evolution project
 * under any OSI approved OSS license (http://www.opensource.org/licenses/).
 * }}
 *
 * @package admin
 *
 * {@internal Below is a list of authors who have contributed to design/coding of this file: }}
 * @author blueyed: Daniel HAHLER.
 *
 * @version $Id$ }}}
 *
 * @todo: Permissions!
 * @todo: favorite folders/bookmarks
 *
 */
if( !defined('DB_USER') ) die( 'Please, do not access this page directly.' );

/**
 * Includes
 */
require_once dirname(__FILE__).'/_class_filelist.php';


/**
 * TODO: docblock for class
 */
class FileManager extends Filelist
{
	// {{{ class variables
	/**
	 * root ('user', 'user_X' or 'blog_X' - X is id)
	 * @param string
	 */
	var $root;

	/**
	 * root directory
	 * @param string
	 */
	var $root_dir;

	/**
	 * root URL
	 * @param string
	 */
	var $root_url;

	/**
	 * current working directory
	 * @param string
	 */
	var $cwd;


	/**
	 * User preference: sort dirs at top
	 * @var boolean
	 */
	var $dirsattop = true;

	/**
	 * User preference: show hidden files?
	 * @var boolean
	 */
	var $showhidden = true;

	/**
	 * User preference: show permissions like "ls -l" (true) or octal (false)?
	 * @var boolean
	 */
	var $permlikelsl = true;

	// --- going to user options ---
	var $default_chmod_file = 0700;
	var $default_chmod_dir = 0700;


	/* ----- PRIVATE ----- */
	/**
	 * order files by what? (name/type/size/lastm/perms)
	 * 'name' as default.
	 * @var string
	 * @access protected
	 */
	var $order = NULL;

	/**
	 * files ordered ascending?
	 * NULL is default and means ascending for 'name', descending for the rest
	 * @var boolean
	 * @access protected
	 */
	var $orderasc = NULL;

	/**
	 * relative path
	 * @var string
	 * @access protected
	 */
	var $path = '';

	// }}}


	/**
	 * Constructor
	 *
	 * @param User the current User {@link User}}
	 * @param string the URL where the object is included (for generating links)
	 * @param string the root directory ('user', 'user_X', 'blog_X')
	 * @param string the dir of the Filemanager object (relative to root)
	 * @param string filter files by what?
	 * @param boolean is the filter a regular expression (default is glob pattern)
	 * @param string order files by what? (NULL means 'name')
	 * @param boolean order ascending or descending? NULL means ascending for 'name', descending for other
	 */
	function FileManager( &$cUser, $url, $root, $path = '', $filter = NULL, $filter_regexp = NULL, $order = NULL, $asc = NULL )
	{
		global $basepath, $baseurl, $media_subdir, $core_dirout, $admin_subdir, $admin_url;
		global $BlogCache;

		$this->User =& $cUser;
		$this->Messages =& new Log( 'error' );


		// {{{ -- get/translate root directory ----
		$this->root = $root;

		$root_A = explode( '_', $this->root );

		if( count($root_A) == 2 && $root_A[1] !== '' )
		{
			switch( $root_A[0] )
			{
				case 'blog':
					$tBlog = $BlogCache->get_by_ID( $root_A[1] );
					$this->root_dir = $tBlog->get( 'mediadir' );
					$this->root_url = $tBlog->get( 'mediaurl' );
					break;

				case 'user':
					$tUser = new User( get_userdata($root_A[1]) );
					$this->root_dir = $tUser->get_mediadir( $this->Messages );
					$this->root_url = $tUser->get_mediaurl();
					break;
			}
		}
		else switch( $root_A[0] )
		{
			case NULL:
			case 'user':
				$this->root_dir = $this->User->get_mediadir( $this->Messages );
				$this->root_url = $this->User->get_mediaurl();
				break;
		}

		list( $real_root_dir, $real_root_dir_exists ) = str2path( $this->root_dir );
		$this->debug( $real_root_dir, 'real_root_dir' );

		if( !$this->root_dir )
		{
			$this->Messages->add( T_('No access to root directory.'), 'error' );
			$this->cwd = NULL;
		}
		elseif( !$real_root_dir_exists )
		{
			$this->Messages->add( sprintf( T_('The root directory [%s] does not exist.'), $this->root_dir ), 'error' );
			$this->cwd = NULL;
		}
		else
		{
			$this->cwd = trailing_slash( $this->root_dir.$path );
			// get real cwd

			list( $realpath, $realpath_exists ) = str2path( $this->cwd );
			$this->debug( $realpath, 'realpath' );


			if( !preg_match( '#^'.$this->root_dir.'#', $realpath ) )
			{ // cwd is not below root!
				$this->Messages->add( T_( 'You are not allowed to go outside your root directory!' ) );
				$this->cwd = $this->root_dir;
			}
			else
			{ // allowed
				if( !$realpath_exists )
				{ // does not exist
					$this->Messages->add( sprintf( T_('The directory [%s] does not exist.'), $this->cwd ) );
					$this->cwd = NULL;
				}
				else
				{
					$this->cwd = $realpath;
				}
			}
		}


		// get the subpath relative to root
		$this->path = preg_replace( '#^'.$this->root_dir.'#', '', $this->cwd );
		// }}}


		$this->url = $url; // base URL, used for created links

		$this->filter = $filter;
		$this->filter_regexp = $filter_regexp;

		if( $this->filter_regexp && !is_regexp( $this->filter ) )
		{
			$this->Messages->add( sprintf( T_('The filter [%s] is not a regular expression.'), $this->filter ) );
			$this->filter = '.*';
		}
		$this->order = ( in_array( $order, array( 'name', 'type', 'size', 'lastm', 'perms' ) ) ? $order : NULL );
		$this->orderasc = ( $asc === NULL  ? NULL : (bool)$asc );

		$this->loadSettings();


		// path/url for images (icons)
		$this->imgpath = $basepath.$admin_subdir.'img/fileicons/';
		$this->imgurl = $admin_url.'img/fileicons/';


		$this->debug( $this->root, 'root' );
		$this->debug( $this->root_dir, 'root_dir' );
		$this->debug( $this->root_url, 'root_url' );
		$this->debug( $this->cwd, 'cwd' );
		$this->debug( $this->path, 'path' );


		// load file icons
		require( $core_dirout.$admin_subdir.'img/fileicons/fileicons.php' );

		/**
		 * These are the filetypes. The extension is a regular expression that must match the end of the file.
		 */
		$this->filetypes = array( // {{{
			'.ai' => T_('Adobe illustrator'),
			'.bmp' => T_('Bmp image'),
			'.bz'  => T_('Bz Archive'),
			'.c' => T_('Source C '),
			'.cgi' => T_('CGI file'),
			'.conf' => T_('Config file'),
			'.cpp' => T_('Source C++'),
			'.css' => T_('Stylesheet'),
			'.exe' => T_('Executable'),
			'.gif' => T_('Gif image'),
			'.gz'  => T_('Gz Archive'),
			'.h' => T_('Header file'),
			'.hlp' => T_('Help file'),
			'.htaccess' => T_('Apache file'),
			'.htm' => T_('Hyper text'),
			'.html' => T_('Hyper text'),
			'.htt' => T_('Windows access'),
			'.inc' => T_('Include file'),
			'.inf' => T_('Config File'),
			'.ini' => T_('Setting file'),
			'.jpe?g' => T_('Jpeg Image'),
			'.js'  => T_('JavaScript'),
			'.log' => T_('Log file'),
			'.mdb' => T_('Access DB'),
			'.midi' => T_('Media file'),
			'.php' => T_('PHP script'),
			'.phtml' => T_('php file'),
			'.pl' => T_('Perl script'),
			'.png' => T_('Png image'),
			'.ppt' => T_('MS Power point'),
			'.psd' => T_('Photoshop Image'),
			'.ra' => T_('Real file'),
			'.ram' => T_('Real file'),
			'.rar' => T_('Rar Archive'),
			'.sql' => T_('SQL file'),
			'.te?xt' => T_('Text document'),
			'.tgz' => T_('Tar gz archive'),
			'.vbs' => T_('MS Vb script'),
			'.wri' => T_('Document'),
			'.xml' => T_('XML file'),
			'.zip' => T_('Zip Archive'),
		); // }}}


		// the directory entries
		parent::Filelist( $this->cwd, $this->filter, $this->filter_regexp, $this->showhidden );
		parent::load();
		parent::restart();

		$this->debug( $this->entries, 'Filelist' );
	}


	/**
	 * Sort the Filelist entries
	 */
	function sort()
	{
		parent::sort( $this->translate_order( $this->order ),
									$this->translate_asc( $this->orderasc, $this->translate_order( $this->order ) ),
									$this->dirsattop );

	}


	/**
	 * get the current url, with all relevant GET params (cd, order, asc)
	 *
	 * @param string override root (blog_X or user_X)
	 * @param string override cd
	 * @param string override order
	 * @param integer override asc
	 */
	function curl( $root = NULL, $path = NULL, $filter = NULL, $filter_regexp = NULL, $order = NULL, $orderasc = NULL )
	{
		$r = $this->url;

		foreach( array('root', 'path', 'filter', 'filter_regexp', 'order', 'orderasc') as $check )
		{
			if( $$check === false )
			{ // don't include
				continue;
			}
			if( $$check !== NULL )
			{ // use local param
				$r = url_add_param( $r, $check.'='.$$check );
			}
			elseif( $this->$check !== NULL )
			{
				$r = url_add_param( $r, $check.'='.$this->$check );
			}
		}

		return $r;
	}


	/**
	 * generates hidden input fields for forms, based on {@link curl()}}
	 */
	function form_hiddeninputs( $root = NULL, $path = NULL, $filter = NULL, $filter_regexp = NULL, $order = NULL, $asc = NULL )
	{
		// get curl(), remove leading URL and '?'
		$params = preg_split( '/&amp;/', substr( $this->curl( $root, $path, $filter, $filter_regexp, $order, $asc ), strlen( $this->url )+1 ) );

		$r = '';
		foreach( $params as $lparam )
		{
			if( $pos = strpos($lparam, '=') )
			{
				$r .= '<input type="hidden" name="'.substr( $lparam, 0, $pos ).'" value="'.format_to_output( substr( $lparam, $pos+1 ), 'formvalue' ).'" />';
			}
		}

		return $r;
	}


	/**
	 * Get an array of available root directories.
	 *
	 * @return array of arrays for each root: array( type [blog/user], id, name )
	 */
	function get_roots()
	{
		global $BlogCache;

		$bloglist = $BlogCache->load_user_blogs( 'browse', $this->User->ID );

		$r = array();

		foreach( $bloglist as $blog_ID )
		{
			$Blog = & $BlogCache->get_by_ID( $blog_ID );

			$r[] = array( 'type' => 'blog',
											'id' => $blog_ID,
											'name' => $Blog->get( 'shortname' ) );
		}

		$r[] = array( 'type' => 'user',
										'name' => T_('user media folder') );

		return $r;
	}


	function link_sort( $type, $atext )
	{
		$r = '<a href="'
					.$this->curl( NULL, NULL, NULL, NULL, $type, false );

		if( $this->order == $type )
		{ // change asc
			$r .= '&amp;asc='.(1 - $this->is_sortingasc());
		}

		$r .= '" title="'
					.( ($this->order == $type && !$this->is_sortingasc($type))
						|| ( $this->order != $type && $this->is_sortingasc($type) )
							? T_('sort ascending by this column') : T_('sort descending by this column')
					).'">'.$atext.'</a>';

		if( $this->order == $type )
		{ // add asc/desc image
			if( $this->is_sortingasc() )
				$r .= ' '.$this->get_icon( 'ascending', 'imgtag' );
			else
				$r .= ' '.$this->get_icon( 'descending', 'imgtag' );
		}

		return $r;
	}


	/**
	 * get the next File in the Filelist
	 *
	 * @param string can be used to query only 'file's or 'dir's.
	 * @return boolean File object on success, false on end of list
	 */
	function get_File_next( $type = '' )
	{
		$this->cur_File = parent::get_File_next( $type );

		return $this->cur_File;
	}


	function get_File_type( $File = NULL )
	{
		if( $File === NULL )
		{
			$File = $this->cur_File;
		}
		elseif( is_string( $File ) )
		{{{ // special names
			switch( $File )
			{
				case 'parent':
					return T_('go to parent directory');
				case 'home':
					return T_('home directory');
				case 'descending':
					return T_('descending');
				case 'ascending':
					return T_('ascending');
				case 'edit':
					return T_('Edit');
				case 'copymove':
					return T_('Copy/Move');
				case 'rename':
					return T_('Rename');
				case 'delete':
					return T_('Delete');
				case 'window_new':
					return T_('Open in new window');
			}
			return false;
		}}}

		if( $File->get_type() == 'dir' )
		{
			return T_('directory');
		}
		else
		{
			$filename = $File->get_name();
			foreach( $this->filetypes as $type => $desc )
			{
				if( preg_match('/'.$type.'$/i', $filename) )
				{
					return $desc;
				}
			}
			return T_('unknown');
		}
	}


	/**
	 * get the URL to access a file
	 *
	 * @param File the File object
	 */
	function get_File_url( $File )
	{
		if( method_exists( $File, 'get_name' ) )
		{
			return $this->root_url.$this->path.$File->get_name();
		}
		else
		{
			return false;
		}
	}


	function get_link_curfile( $param = '' )
	{
		if( $this->cur_File->get_type() == 'dir' && $param != 'forcefile' )
		{
			return $this->curl( NULL, $this->path.$this->cur_File->get_name() );
		}
		else
		{
			return $this->curl( NULL, $this->path ).'&amp;file='.urlencode( $this->cur_File->get_name() );
		}
	}


	function get_link_curfile_editperm()
	{
		return $this->get_link_curfile('forcefile').'&amp;action=editperm';
	}


	function get_link_curfile_edit()
	{
		if( $this->cur_File->get_type() == 'dir' )
		{
			return false;
		}
		return $this->get_link_curfile().'&amp;action=edit';
	}


	function get_link_curfile_copymove()
	{
		if( $this->cur_File->get_type() == 'dir' )
		{
			return false;
		}
		return $this->get_link_curfile().'&amp;action=copymove';
	}


	/**
	 * get link to delete current file
	 * @return string the URL
	 */
	function get_link_curfile_rename()
	{
		return $this->get_link_curfile('forcefile').'&amp;action=rename';
	}


	/**
	 * get link to delete current file
	 * @return string the URL
	 */
	function get_link_curfile_delete()
	{
		return $this->get_link_curfile('forcefile').'&amp;action=delete';
	}


	/**
	 * get the link to the parent folder
	 * @return mixed URL or false if
	 */
	function get_link_parent()
	{
		if( empty($this->path) )
		{ // cannot go higher
			return false;
		}
		return $this->curl( NULL, $this->path.'..' );
	}


	/**
	 * get the link to current's root's home directory
	 */
	function get_link_home()
	{
		return $this->curl( 'user', false );
	}


	/**
	 * Get an attribute of the current entry,
	 *
	 * @param string property
	 * @param string optional parameter
	 * @param string gets through sprintf where %s gets replaced with the result
	 */
	function depr_cget( $what, $param = '', $displayiftrue = '' )
	{
		echo '<p class="error">bad cget: ['.$what.']</p>';
		return;
		switch( $what )
		{
			case 'type':
				$r = $this->type( 'cfile' );
				break;

			case 'iconfile':
				$r = $this->get_icon( 'cfile', 'file' );
				break;

			case 'iconurl':
				$r = $this->get_icon( 'cfile', 'url' );
				break;

			case 'iconsize':
				$r = $this->get_icon( 'cfile', 'size', $param );
				break;

			default:
				$r = ( isset( $this->current_entry[ $what ] ) ) ? $this->current_entry[ $what ] : false;
				break;
		}
		if( $r && !empty($displayiftrue) )
		{
			return sprintf( $displayiftrue, $r );
		}
		else
			return $r;
	}


	/**
	 * get properties of a special icon
	 *
	 * @param string icon for what (special puposes or 'cfile' for current file/dir)
	 * @param string what to return for that icon (file, url, size {@link see imgsize()}})
	 * @param string additional parameter (for size)
	 */
	function get_icon( $for, $what = 'imgtag', $param = '' )
	{
		if( $for == 'cfile' )
		{
			if( !$this->cur_File )
			{
				$iconfile = false;
			}
			elseif( $this->cur_File->get_type() == 'dir' )
			{
				$iconfile = $this->fileicons_special['folder'];
			}
			else
			{
				$iconfile = $this->fileicons_special['unknown'];
				$filename = $this->cur_File->get_name();
				foreach( $this->fileicons as $ext => $imgfile )
				{
					if( preg_match( '/'.$ext.'$/i', $filename, $match ) )
					{
						$iconfile = $imgfile;
						break;
					}
				}
			}
		}
		elseif( isset( $this->fileicons_special[$for] ) )
		{
			$iconfile = $this->fileicons_special[$for];
		}
		else $iconfile = false;

		if( !$iconfile || !file_exists( $this->imgpath.$iconfile ) )
		{
			#return '<div class="error">[no image for '.$for.'!]</div>';
			return false;
		}

		switch( $what )
		{
			case 'file':
				$r = $iconfile;
				break;

			case 'url':
				$r = $this->imgurl.$iconfile;
				break;

			case 'size':
				$r = imgsize( $this->imgpath.$iconfile, $param );
				break;

			case 'imgtag':
				$r = '<img class="middle" src="'.$this->get_icon( $for, 'url' ).'" '.$this->get_icon( $for, 'size', 'string' )
				.' alt="';

				if( $for == 'cfile' )
				{ // extension as alt-tag for cfile-icons
					$r .= $this->cur_File->get_ext();
				}

				$r .= '" title="'.$this->get_File_type( $for );

				$r .= '" />';
				break;


			default:
				echo 'unknown what: '.$what;
		}

		return $r;
	}


	/**
	 * do actions to a file/dir
	 *
	 * @param string filename (in cwd)
	 * @param string the action (chmod)
	 * @param string parameter for action
	 */
	function cdo_file( $filename, $what, $param = '' )
	{
		if( $this->loadc( $filename ) )
		{
			$path = $this->cget( 'path' );
			switch( $what )
			{
				case 'send':
					if( is_dir($path) )
					{ // we cannot send directories!
						return false;
					}
					else
					{
						header('Content-type: application/octet-stream');
						//force download dialog
						header('Content-disposition: attachment; filename="' . $filename . '"');

						header('Content-transfer-encoding: binary');
						header('Content-length: ' . filesize($path));

						//send file contents
						readfile($path);
						exit;
					}
			}
		}
		else
		{
			$this->Messages->add( sprintf( T_('File [%s] not found.'), $filename ) );
			return false;
		}

		$this->restorec();
		return $r;
	}


	/**
	 * Remove a file or directory.
	 *
	 * @param string filename, defaults to current loop entry
	 * @param boolean delete subdirs of a dir?
	 * @return boolean true on success, false on failure
	 */
	function delete( $file = NULL, $delsubdirs = false )
	{
		// TODO: permission check

		if( $file == NULL )
		{ // use current entry
			if( isset($this->current_entry) )
			{
				$entry = $this->current_entry;
			}
			else
			{
				$this->Messages->add('delete: no current file!');
				return false;
			}
		}
		else
		{ // use a specific entry
			if( ($key = $this->findkey( $file )) !== false )
			{
				$entry = $this->entries[$key];
			}
			else
			{
				$this->Messages->add( sprintf(T_('File [%s] not found.'), $file) );
				return false;
			}
		}

		if( $entry['type'] == 'dir' )
		{
			if( $delsubdirs )
			{
				if( deldir_recursive( $this->cwd.'/'.$entry['name'] ) )
				{
					$this->Messages->add( sprintf( T_('Directory [%s] and subdirectories deleted.'), $entry['name'] ), 'note' );
					return true;
				}
				else
				{
					$this->Messages->add( sprintf( T_('Directory [%s] could not be deleted.'), $entry['name'] ) );
					return false;
				}
			}
			elseif( @rmdir( $this->cwd.'/'.$entry['name'] ) )
			{
				$this->Messages->add( sprintf( T_('Directory [%s] deleted.'), $entry['name'] ), 'note' );
				return true;
			}
			else
			{
				$this->Messages->add( sprintf( T_('Directory [%s] could not be deleted (probably not empty).'), $entry['name'] ) );
				return false;
			}
		}
		else
		{
			if( unlink( $this->cwd.'/'.$entry['name'] ) )
			{
				$this->Messages->add( sprintf( T_('File [%s] deleted.'), $entry['name'] ), 'note' );
				return true;
			}
			else
			{
				$this->Messages->add( sprintf( T_('File [%s] could not be deleted.'), $entry['name'] ) );
				return false;
			}
		}
	}


	/**
	 * Create a root dir, while making the suggested name an safe filename.
	 * @param string the path where to create the directory
	 * @param string suggested dirname, will be converted to a safe dirname
	 * @param integer permissions for the new directory (octal format)
	 * @return mixed full path that has been created; false on error
	 */
	function create_rootdir( $path, $suggested_name, $chmod = NULL )
	{
		global $Debuglog;

		if( $chmod === NULL )
		{
			$chmod = $this->default_chmod_dir;
		}

		$realname = safefilename( $suggested_name );
		if( $realname != $suggested_name )
		{
			$Debuglog->add( 'Realname for dir ['.$suggested_name.']: ['.$realname.']', 'fileman' );
		}
		if( $this->createdir( $realname, $path, $chmod ) )
		{
			return $path.'/'.$realname;
		}
		else
		{
			return false;
		}
	}


	/**
	 * Create a directory.
	 * @param string the name of the directory
	 * @param string path to create the directory in (default is cwd)
	 * @param integer permissions for the new directory (octal format)
	 * @return boolean true on success, false on failure
	 */
	function createdir( $dirname, $path = NULL, $chmod = NULL )
	{
		if( $path == NULL )
		{
			$path = $this->cwd;
		}
		if( substr( $path, -1 ) != '/' )
		{
			$path .= '/';
		}
		if( $chmod == NULL )
		{
			$chmod = $this->default_chmod_dir;
		}
		if( empty($dirname) )
		{
			$this->Messages->add( T_('Cannot create empty directory.') );
			return false;
		}
		elseif( !mkdir( $path.$dirname, $chmod ) )
		{
			$this->Messages->add( sprintf( T_('Could not create directory [%s] in [%s].'), $dirname, $path ) );
			return false;
		}

		$this->Messages->add( sprintf( T_('Directory [%s] created.'), $dirname ), 'note' );
		return true;
	}


	/**
	 * Create a file
	 * @param string filename
	 * @param integer permissions for the new file (octal format)
	 */
	function createfile( $filename, $chmod = NULL )
	{
		$path = $this->cwd.'/'.$filename;

		if( $chmod == NULL )
		{
			$chmod = $this->default_chmod_file;
		}

		if( empty($filename) )
		{
			$this->Messages->add( T_('Cannot create empty file.') );
			return false;
		}
		elseif( file_exists($path) )
		{
			// TODO: allow overwriting
			$this->Messages->add( sprintf(T_('File [%s] already exists.'), $filename) );
			return false;
		}
		elseif( !touch( $path ) )
		{
			$this->chmod( $filename, $chmod );
			$this->Messages->add( sprintf( T_('Could not create file [%s] in [%s].'), $filename, $this->cwd ) );
			return false;
		}
		else
		{
			$this->Messages->add( sprintf( T_('File [%s] created.'), $filename ), 'note' );
			return true;
		}
	}


	/**
	 * Reloads the page where Filemanager was called for, useful when a file or dir has been created.
	 */
	function reloadpage()
	{
		header( 'Location: '.$this->curl() );
		exit;
	}


	function debug( $what, $desc, $forceoutput = 0 )
	{
		global $Debuglog;

		ob_start();
		pre_dump( $what, '[Fileman] '.$desc );
		$Debuglog->add( ob_get_contents() );
		if( $forceoutput )
		{
			ob_end_flush();
		}
		else
		{
			ob_end_clean();
		}
	}


	/**
	 * returns cwd, where the accessible directories (below root)  are clickable
	 * @return string cwd as clickable html
	 */
	function cwd_clickable()
	{
		if( !$this->cwd )
		{
			return ' -- '.T_('No directory.').' -- ';
		}
		// not clickable
		$r = substr( $this->root_dir, 0, strrpos( substr($this->root_dir, 0, -1), '/' )+1 );

		// get the part that is clickable
		$clickabledirs = explode( '/', substr( $this->cwd, strlen($r) ) );

		$r .= '<a href="'.$this->curl( NULL, false ).'">'.array_shift( $clickabledirs ).'</a>';
		$cd = '';
		foreach( $clickabledirs as $nr => $dir )
		{
			if( empty($dir) )
			{
				break;
			}
			$r .= '/<a href="'.$this->curl( NULL, $cd.$dir ).'">'.$dir.'</a>';
			$cd .= $dir.'/';
		}

		return $r;
	}


	/**
	 * get prefs from user's Settings
	 */
	function loadSettings()
	{
		global $UserSettings;

		$UserSettings->get_cond( $this->dirsattop,        'fm_dirsattop',        $this->User->ID );
		$UserSettings->get_cond( $this->permlikelsl,      'fm_permlikelsl',      $this->User->ID );
		$UserSettings->get_cond( $this->recursivedirsize, 'fm_recursivedirsize', $this->User->ID ); // TODO: check for permission (Server load)
		$UserSettings->get_cond( $this->showhidden,       'fm_showhidden',       $this->User->ID );
	}


	/**
	 * check permissions
	 *
	 * @param string for what? (upload)
	 * @return true if permission granted, false if not
	 */
	function perm( $for )
	{
		global $Debuglog;

		switch( $for )
		{
			case 'upload':
				return $this->User->check_perm( 'upload', 'any', false );

			default:  // return false if not defined
				$Debuglog->add( 'Filemanager: permission check for ['.$for.'] not defined!' );
				return false;
		}
	}


	/**
	 * translates $asc parameter, if it's NULL
	 * @param boolean sort ascending?
	 * @return integer 1 for ascending, 0 for descending
	 */
	function translate_asc( $asc, $order )
	{
		if( $asc !== NULL )
		{
			return $asc;
		}
		elseif( $this->orderasc !== NULL )
		{
			return $this->orderasc;
		}
		else
		{
			return ($order == 'name') ? 1 : 0;
		}
	}


	/**
	 * translates $order parameter, if it's NULL
	 * @param string order by?
	 * @return string order by what?
	 */
	function translate_order( $order )
	{
		if( $order !== NULL )
		{
			return $order;
		}
		elseif( $this->order !== NULL )
		{
			return $this->order;
		}
		else
		{
			return 'name';
		}
	}


}
?>