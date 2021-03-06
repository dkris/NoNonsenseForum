<?php  //commit actions on posts, like delete, append &c.
/* ====================================================================================================================== */
/* NoNonsense Forum v8 © Copyright (CC-BY) Kroc Camen 2011
   licenced under Creative Commons Attribution 3.0 <creativecommons.org/licenses/by/3.0/deed.en_GB>
   you may do whatever you want to this code as long as you give credit to Kroc Camen, <camendesign.com>
*/

require_once './shared.php';

/* append to a post?
   ====================================================================================================================== */
if (isset ($_GET['append'])) {
	//thread to deal with
	$FILE = (preg_match ('/^[^.\/]+$/', @$_GET['file']) ? $_GET['file'] : '') or die ('Malformed request');
	
	//ID of post appending to
	$ID = (preg_match ('/^[A-Z0-9]+$/i', @$_GET['id']) ? $_GET['id'] : '') or die ('Malformed request');
	
	//get the post message, the other fields (name / pass) are retrieved automatically in 'shared.php'
	define ('TEXT', safeGet (@$_POST['text'], SIZE_TEXT));
	
	//get a write lock on the file so that between now and saving, no other posts could slip in
	$f = fopen ("$FILE.rss", 'r+'); flock ($f, LOCK_EX);
	
	//load the thread to get the post preview
	$xml = simplexml_load_string (fread ($f, filesize ("$FILE.rss")), 'allow_prepend') or die ('Malformed XML');
	
	//find the post using the ID
	for ($i=0; $i<count ($xml->channel->item); $i++) if (
		strstr ($xml->channel->item[$i]->link, '#') == "#$ID"
	) break;
	$post = $xml->channel->item[$i];
	
	/* has the un/pw been submitted to authenticate the append?
	   -------------------------------------------------------------------------------------------------------------- */
	if (AUTH && TEXT && FORUM_ENABLED && (
		//- if you are a moderator (doesn’t matter if the forum or thread is locked)
		IS_MOD ||
		//- if you are a member, the forum lock doesn’t matter, but you can’t reply to locked threads (only mods can)
		(!(bool) $xml->channel->xpath ("category[text()='locked']") && IS_MEMBER) ||
		//- if you are neither a mod nor a member, then as long as: 1. the thread is not locked, and
		//  2. the forum is such that anybody can reply (unlocked or thread-locked), then you can reply
		(!(bool) $xml->channel->xpath ("category[text()='locked']") && (!FORUM_LOCK || FORUM_LOCK == 'threads'))
	) && (
		//a moderator can always append
		IS_MOD ||
		//the owner of a post can append
		(strtolower (NAME) == strtolower ($post->author) && (
			//if the forum is post-locked, they must be a member to append to their own posts
			(!FORUM_LOCK || FORUM_LOCK == 'threads') || IS_MEMBER
		))
	)) {
		$now = time ();
		$post->description .= "\n".template_tags (TEMPLATE_APPEND, array (
			'AUTHOR'	=> safeHTML (NAME),
			'DATETIME'	=> gmdate ('r', $now),
			'TIME'		=> date (DATE_FORMAT, $now)
		)).formatText (TEXT);
		
		//need to know what page this post is on to redirect back to it
		$page = ceil ((count ($xml->channel->item)-1-$i) / FORUM_POSTS);
		
		//commit the data
		rewind ($f); ftruncate ($f, 0); fwrite ($f, $xml->asXML ());
		//close the lock / file
		flock ($f, LOCK_UN); fclose ($f);
		
		//try set the modified date of the file back to the time of the last comment
		//(appending to a post does not push the thread back to the top of the list)
		//note: this may fail if the file is not owned by the Apache process
		@touch ("$FILE.rss", strtotime ($xml->channel->item[0]->pubDate));
		
		//regenerate the folder's RSS file
		indexRSS ();
		
		//return to the appended post
		header ('Location: '.FORUM_URL.PATH_URL."$FILE?page=$page#$ID", true, 303);
		exit;
	}
	
	//close the lock / file
	flock ($f, LOCK_UN); fclose ($f);
	
	/* prepare template
	   -------------------------------------------------------------------------------------------------------------- */
	$HEADER = array (
		'TITLE'		=> safeHTML ($xml->channel->title)
	);
	
	$FORM = array (
		'NAME'		=> safeString (NAME),
		'PASS'		=> safeString (PASS),
		'TEXT'		=> safeString (TEXT),
		'ERROR'		=> empty ($_POST) ? ERROR_NONE
				 : (!NAME ? ERROR_NAME
				 : (!PASS ? ERROR_PASS
				 : ERROR_AUTH))
	);
	
	$POST = array (
		'TITLE'		=> safeHTML ($post->title),
		'AUTHOR'	=> safeHTML ($post->author),
		'MOD'		=> isMod ($post->author),
		'DATETIME'	=> gmdate ('r', strtotime ($post->pubDate)),
		'TIME'		=> date (DATE_FORMAT, strtotime ($post->pubDate)),
		'TEXT'		=> $post->description
	);
	
	//all the data prepared, now output the HTML
	include FORUM_ROOT.'/themes/'.FORUM_THEME.'/append.inc.php';
	
	
/* delete a thread / post?
   ====================================================================================================================== */
} elseif (isset ($_GET['delete'])) {
	//thread to deal with
	$FILE = (preg_match ('/^[^.\/]+$/', @$_GET['file']) ? $_GET['file'] : '') or die ('Malformed request');
	
	//if deleting just one post, rather than the thread
	$ID = (preg_match ('/^[A-Z0-9]+$/i', @$_GET['id']) ? $_GET['id'] : false);
	
	//get a write lock on the file so that between now and saving, no other posts could slip in
	$f = fopen ("$FILE.rss", 'r+'); flock ($f, LOCK_EX);
	
	//load the thread to get the post preview
	$xml = simplexml_load_string (fread ($f, filesize ("$FILE.rss")), 'allow_prepend') or die ('Malformed XML');
	
	//access the particular post. if no ID is provided (deleting the whole thread) use the last item in the RSS file
	//(the first post), otherwise find the ID of the specific post
	if (!$ID) {
		$post = $xml->channel->item[count ($xml->channel->item) - 1];
	} else {
		//find the post using the ID
		for ($i=0; $i<count ($xml->channel->item); $i++) if (
			strstr ($xml->channel->item[$i]->link, '#') == "#$ID"
		) break;
		$post = $xml->channel->item[$i];
	}
	
	/* has the un/pw been submitted to authenticate the delete?
	   -------------------------------------------------------------------------------------------------------------- */
	if (AUTH && FORUM_ENABLED && (
		//- if you are a moderator (doesn’t matter if the forum or thread is locked)
		IS_MOD ||
		//- if you are a member, the forum lock doesn’t matter, but you can’t reply to locked threads (only mods can)
		(!(bool) $xml->channel->xpath ("category[text()='locked']") && IS_MEMBER) ||
		//- if you are neither a mod nor a member, then as long as: 1. the thread is not locked, and
		//  2. the forum is such that anybody can reply (unlocked or thread-locked), then you can reply
		(!(bool) $xml->channel->xpath ("category[text()='locked']") && (!FORUM_LOCK || FORUM_LOCK == 'threads'))
	) && (
		//a moderator can always delete
		IS_MOD ||
		//the owner of a post can delete
		(strtolower (NAME) == strtolower ($post->author) && (
			//if the forum is post-locked, they must be a member to delete their own posts
			(!FORUM_LOCK || FORUM_LOCK == 'threads') || IS_MEMBER
		))
	//deleting a post?
	)) if ($ID) {
		//full delete? (option ticked, is moderator, and post is on the last page)
		if (
			(IS_MOD && $i <= (count ($xml->channel->item)-2) % FORUM_POSTS) &&
			//if the post has already been blanked, delete it fully
			(isset ($_POST['remove']) || $post->xpath ("category[text()='deleted']"))
		) {
			//remove the post from the thread entirely
			unset ($xml->channel->item[$i]);
			
			//we’ll redirect to the last page (which may have changed when the post was deleted)
			$url = FORUM_URL.PATH_URL."$FILE?page=last#replies";
			
		} else {
			//remove the post text
			$post->description = (NAME == (string) $post->author) ? TEMPLATE_DEL_USER : TEMPLATE_DEL_MOD;
			//add a "deleted" category so we know to no longer allow it to be edited or deleted again
			if (!$post->xpath ("category[text()='deleted']")) $post->category[] = 'deleted';
			
			//need to know what page this post is on to redirect back to it
			$url = FORUM_URL.PATH_URL."$FILE?page=".ceil ((count ($xml->channel->item)-1-$i) / FORUM_POSTS)."#$ID";
		}
		
		//commit the data
		rewind ($f); ftruncate ($f, 0); fwrite ($f, $xml->asXML ());
		//close the lock / file
		flock ($f, LOCK_UN); fclose ($f);
		
		//try set the modified date of the file back to the time of the last comment
		//(so that deleting does not push the thread back to the top of the list)
		//note: this may fail if the file is not owned by the Apache process
		@touch ("$FILE.rss", strtotime ($xml->channel->item[0]->pubDate));
		
		//regenerate the folder's RSS file
		indexRSS ();
		
		//return to the deleted post / last page
		header ("Location: $url", true, 303);
		exit;
	
	} else {
		//close the lock / file
		flock ($f, LOCK_UN); fclose ($f);
		
		//delete the thread for reals
		@unlink (FORUM_ROOT.PATH_DIR."$FILE.rss");
		
		//regenerate the folder's RSS file
		indexRSS ();
		
		//return to the index
		header ('Location: '.FORUM_URL.PATH_URL, true, 303);
		exit;
	}
	
	//close the lock / file
	flock ($f, LOCK_UN); fclose ($f);
	
	/* prepare template
	   -------------------------------------------------------------------------------------------------------------- */
	$HEADER = array (
		'TITLE'		=> safeHTML ($xml->channel->title)
	);
	
	$FORM = array (
		'NAME'		=> safeString (NAME),
		'PASS'		=> safeString (PASS),
		'ERROR'		=> empty ($_POST) ? ERROR_NONE
				 : (!NAME ? ERROR_NAME
				 : (!PASS ? ERROR_PASS
				 : ERROR_AUTH))
	);
	
	$POST = array (
		'AUTHOR'	=> safeHTML ($post->author),
		'MOD'		=> isMod ($post->author),
		'DATETIME'	=> gmdate ('r', strtotime ($post->pubDate)),
		'TIME'		=> date (DATE_FORMAT, strtotime ($post->pubDate)),
		'TEXT'		=> $post->description
	);
	
	//all the data prepared, now output the HTML
	include FORUM_ROOT.'/themes/'.FORUM_THEME.'/delete.inc.php';


/* un/lock a thread?
   ====================================================================================================================== */
} elseif (isset ($_GET['lock'])) {
	//thread to deal with
	$FILE = (preg_match ('/^[^.\/]+$/', @$_GET['file']) ? $_GET['file'] : '') or die ('Malformed request');
	
	//get a write lock on the file so that between now and saving, no other posts could slip in
	$f   = fopen ("$FILE.rss", 'r+'); flock ($f, LOCK_EX);
	$xml = simplexml_load_string (fread ($f, filesize ("$FILE.rss")), 'allow_prepend') or die ('Malformed XML');
	
	//what’s the current status?
	$LOCKED = (bool) $xml->channel->xpath ("category[text()='locked']");
	
	/* has the un/pw been submitted to authenticate the un/lock? (only a moderator can un/lock a thread)
	   -------------------------------------------------------------------------------------------------------------- */
	if (IS_MOD && AUTH) {
		if ($LOCKED) {
			//if there’s a "locked" category, remove it
			//note: for simplicity this removes *all* channel categories as NNF only uses one atm,
			//      in the future the specific "locked" category needs to be removed
			unset ($xml->channel->category);
			//when unlocking, go to the thread
			$url = FORUM_URL.PATH_URL."$FILE?page=last#reply";
		} else {
			//if no "locked" category, add it
			$xml->channel->category[] = 'locked';
			//if locking return to the index
			//(todo: could return to the particular page in the index the thread is on--complex!)
			$url = FORUM_URL.PATH_URL;
		}
		
		//commit the data
		rewind ($f); ftruncate ($f, 0); fwrite ($f, $xml->asXML ());
		//close the lock / file
		flock ($f, LOCK_UN); fclose ($f);
		
		//regenerate the folder's RSS file
		indexRSS ();
		
		header ("Location: $url", true, 303);
		exit;
	}
	
	//close the lock / file
	flock ($f, LOCK_UN); fclose ($f);
	
	/* prepare template
	   -------------------------------------------------------------------------------------------------------------- */
	$HEADER = array (
		'TITLE'	=> safeHTML ($xml->channel->title)
	);
	
	$FORM = array (
		'NAME'	=> safeString (NAME),
		'PASS'	=> safeString (PASS),
		'ERROR'	=> empty ($_POST) ? ERROR_NONE
			 : (!NAME ? ERROR_NAME
			 : (!PASS ? ERROR_PASS
			 : ERROR_AUTH))
	);
	
	//all the data prepared, now output the HTML
	include FORUM_ROOT.'/themes/'.FORUM_THEME.'/lock.inc.php';
}
?>