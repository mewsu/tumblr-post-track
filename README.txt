Members: c1kwongk
CDF environment: greywolf
Port: 31170

Documentation:

	This is a REST API that tracks popular posts using the Tumblr API.
	Client must provide a blog-hostname; for which the server will keep track
	of all posts that are liked by this blog, updated hourly.
	Client can then query for posts that have the greatest increment of note counts,
	or for any new posts that the blog has liked.

	Accepted HTTP requests:

		Method: POST /blog
		Description:
			Tells server to start tracking a blog's posts hourly.
			Server will update tracking on the hour, not based on when the blog was added.
		Parameter:
			- blog: a string indicating a new blog to track by its {base-hostname}
		Response:
			HTTP 200 response if
				1) request syntax is correct
				2) parameter is provided
				3) {blog-hostname} is a valid host on Tumblr

				
		Method: GET /blog/{base-hostname}/trends
		Description:
			Asks server to query database for posts originated from {base-hostname}
			Response is based on "order" paramter; "Trending" will return posts that have the
			greatest increment since the last tracking. "Recent" will return posts that have
			been added most recently. The number of posts returned is based on optional
			"limit" parameter; it is 10 if not provided.
		Parameters:
			- limit: the maximum number of results to return (optional)
			- order: a string “Trending” or “Recent” (required)
			- {base-hostname} as the second arugment in the URL. (required)
		Response:
			HTTP 200 and JSON package response if
				1) request syntax is correct
				2) "order" is provided
				3) {blog-hostname} is a valid host on Tumblr and is currently being tracked


		Method: GET /blogs/trends
		Description:
			Same as above; but query instead for posts originating from any host.
		Parameters:
			- limit: the maximum number of results to return (optional)
			- order: a string “Trending” or “Recent” (required)
		Response:
			HTTP 200 and JSON package response if
				1) request syntax is correct
				2) "order" is provided
				
				
	Database Structure:
		The database uses two tables:
		
		Table Name: a2_posts
		Columns:
			1) hostname - the {blog-hostname} from where this post originated.
			2) url - the URL of the original post.
			3) date - date when the post was posted on Tumblr.
			4) image - relevent image link of the post;
			if there are more than one photos in the post, link to the first one is stored.
			5) text - relevent text for the post; this is the last argument of the URL.
		Function: 
			Stores the static information of a post that will stay constant.
			This table is accessed when a new blog to track is requested,
			during an update request, and during a client query request for posts;
			for which information from this table must be packaged as the JSON response.
			
		Table Name: a2_tracking
		Columns:
			1) id -  an auto-increment identifier to keep track of when the tracking was inserted relative to all trackings of all posts;
			this is used as part of the comaprison function during a client getPost request.
			2) hostname - the {blog-hostname} from where this post originated.
			3) url - url - the URL of the original post.
			4) timestamp - exact time when this tracking was inserted
			5) seqeunce - integer to indicate the position of which this tracking
			holds relative to all trackings of the same post. This is also used in
			the getPost function.
			6) increment - increment of note_count since the last tracking
			7) count - current note_count
		Function: 
			This table stores all tracking information about all posts that are
			currently being tracked. New trackings are inserted hourly from making
			queries to Tumblr for updated information. During a  client getPost
			request, information from this table is stored in the "tracking"
			array of the returned JSON package. "id", "sequence", and "increment"
			are also used to determine the ordering of posts in the returned JSON package.
		
	System Components:
		Refer to diagram.jpg for a sequence diagram
	