// update to improve filtering
// establish 90-day boundary
var today = new Date();
today.setHours(0, 0, 0, 0);
var minDate = new Date().setDate(today.getDate() - 90);

// datepicker requires ISO format
minDate = new Date(minDate).toISOString().split("T")[0];

// get info from localStorage, if any
var bookingData = JSON.parse(localStorage.getItem("lexBookingData")) || {};
var inmate = bookingData.inmate || {};
var start = bookingData.start || 0;
var end = bookingData.end || 0;
var qTerms = bookingData.terms || "";

// where is our API?
var ajaxSrc = "./../index.php";
// "https://lexmugs.herokuapp.com";
// "http://dev.nandointeractive.com/mugshots/lexington/";

// for filtering purposes, create an array of stringified detainee data
var filterSource = [];

// global variable autoRun will be used to set timeout on filter keyup function
var autoRun;

$(document).ready(function() {
  // only need to see the filter spinner when filtering is happening ...
  $("#filterSpinner").hide();

  if (type === "detail") {
    // is the inmate object empty?
    if (!jQuery.isEmptyObject(inmate)) {
      displayInmate(inmate);
    } else {
      $("#inmate").html(
        "<p>This page is intended to display details about an inmate recently booked into the Lexington County Detention Center. If you're seeing this message, an error has occurred, or you have not selected an inmate from the main Lexington County Local Arrests page.</p>"
      );
    }
  } else {
    // it's the index page so use stored or default start/end params
    // if no params were placed in local storage, default start and end is today

    var startDate = new Date().setDate(today.getDate() - start);
    startDate = new Date(startDate).toISOString().split("T")[0];
    var endDate = new Date().setDate(today.getDate() - end);
    endDate = new Date(endDate).toISOString().split("T")[0];

    // update date picker to show start and end values
    $("#startDate").val(startDate);
    $("#endDate").val(endDate);

    // if qStart is not null, update date range message
    if (start != null) {
      $("#dateRange").html(
        "Bookings from " +
          toLocaleFromIso(startDate) +
          " to " +
          toLocaleFromIso(endDate)
      );
    }
    // get data using passed parameters
    getData(start, end, qTerms);
  }

  // update date picker with min/max attributes and default values
  $("#startDate,#endDate").attr({
    min: minDate,
    max: new Date().toISOString().split("T")[0]
  });

  // add click listener to date submit button
  $("#dateSubmitBtn").on("click", function() {
    // what are filter terms, if any?
    var terms = $("#filterInput")
      .val()
      .trim()
      .toLowerCase();

    // access form values
    var startVal = $("#startDate").val();
    if (startVal === "") {
      // if no value set days offset to 90
      var startDays = 1;
    } else {
      //compute start days offset
      var start = new Date(startVal);
      var startDays = Math.round((today - start) / (1000 * 60 * 60 * 24));

      // ensure max start is 90 days
      if (startDays > 90) {
        startDays = 90;
      }
    }

    var endVal = $("#endDate").val();
    if (endVal === "") {
      // if no value set days offset to 0
      var endDays = 0;
    } else {
      //compute end days offset
      var end = new Date(endVal);
      var endDays = Math.round((today - end) / (1000 * 60 * 60 * 24));
    }
    // did the user pick an end date earlier than the start date? default to that date
    if (endDays > startDays) {
      endDays = startDays;
    }

    // update date range
    $("#dateRange").html(
      "Bookings from " +
        toLocaleFromIso(startVal) +
        " to " +
        toLocaleFromIso(endVal)
    );

    // update local storage
    var bookingData = JSON.parse(localStorage.getItem("lexBookingData")) || {};
    bookingData.start = startDays;
    bookingData.end = endDays;
    localStorage.setItem("lexBookingData", JSON.stringify(bookingData));

    // fetch new data
    getData(startDays, endDays, terms);
  });

  // add click listener to date shortcut buttons
  $(".dateShortcut").on("click", function() {
    // what button?
    var timeBack = $(this).attr("data-time-back");

    // update date range message
    var message =
      timeBack === "1"
        ? "Today's bookings"
        : "Bookings for the last " + timeBack + " days";

    $("#dateRange").html(message);

    // check for filter text
    var terms = $("#filterInput")
      .val()
      .trim()
      .toLowerCase();

    // update local storage
    var bookingData = JSON.parse(localStorage.getItem("lexBookingData")) || {};
    bookingData.start = timeBack - 1;
    bookingData.end = 0;
    localStorage.setItem("lexBookingData", JSON.stringify(bookingData));

    // fetch data based on button attribute and current filter
    getData(timeBack - 1, 0, terms);
  });
}); // end doc ready

// update stats tag here since there's no byline
// mistats.contentsource="Posted by Kelly Davis | Source: Beaufort County Detention Center";

// function that calls API to retrieve booking data

function getData(start, end, terms) {
  // replace any content in bookingPanel or inmate divs with load spinner
  $("#inmate, #inmates").html(
    '<div id="loadSpinner"><i class="fa fa-spinner fa-pulse fa-5x fa-fw"></i><span class="sr-only">Loading...</span></div>'
  );

  // reset sort buttons and icons to default values
  $("#sortAlpha").attr("data-sort", "asc");
  $("#alphaSortIcon").removeClass();

  $("#sortDate").attr("data-sort", "asc");
  $("#dateSortIcon")
    .removeClass()
    .addClass("fa fa-arrow-down");

  $.get(ajaxSrc + "?start=" + start + "&end=" + end, function(response) {
    if (response.success) {
      console.log(response);
      // are we on index page or details page?
      var pageType = $(".bookings").attr("data-page-type");

      if (pageType === "bookingIndex") {
        // sort desc by booking date/time
        response.data.sort(sortDateDesc);
        displayInmates(response.data, start, end, terms);
      } else if (pageType === "bookingDetails") {
        displayInmate(response.data, start, end, terms);
      }
    } else {
      console.log(response);
      $("#bookingPanel, #inmates").html(
        "<h2>Error</h2><p>There was an error fetching data from the Lexington County Detention Center. Please try again.</p>"
      );
    }
  }).fail(function(err) {
    $("#bookingPanel, #inmates, #inmate").html(
      "<h2>Error</h2><p>There was an error fetching inmate data. Either our server or the Lexington County Detention Center inmate inquiry system is down. You can try again if you wish.</p>"
    );
    console.log("Error retrieving data:", JSON.stringify(err));
  });
}

function displayInmates(data, start, end, terms) {
  // reset filterSource
  filterSource = [];

  // remove any existing html, including the loading spinner
  $("#inmates").html("");

  // loop through inmate data returned from API
  for (var i = 0; i < data.length; i++) {
    var detainee = data[i];

    // clone this inmate's data to clean it up and stringify for filter source
    var detaineeVals = $.extend({}, detainee);
    delete detaineeVals.my_num;
    delete detaineeVals.invid;
    delete detaineeVals.name;
    delete detaineeVals.date_arr;
    delete detaineeVals.disp_charge;
    delete detaineeVals.disp_name;
    delete detaineeVals.chrgdesc;
    delete detaineeVals.image;
    delete detaineeVals.link_text;

    // add detainee object value arrays to filterSource
    filterSource.push(JSON.stringify(detaineeVals).toLowerCase());

    // build content block
    var inmateBlock =
      '<div data-booknum="' +
      detainee.book_id +
      '" class="detaineeIndex col-lg-2 col-md-2 col-sm-4 col-xs-6">';

    // start mugshot/details row
    inmateBlock += '<div class="row"><div class="col-lg-12">';

    // photo; if image error, fall back to "no photo" option
    inmateBlock +=
      '<img onerror="this.src=\'http://media.islandpacket.com/static/news/crime/mugshots/noPhoto.jpg\'" class="bcmugshot" src="' +
      detainee.image +
      '" title="' +
      detainee.firstname +
      " " +
      detainee.middlename +
      " " +
      detainee.lastname +
      '" alt="' +
      detainee.firstname +
      " " +
      detainee.middlename +
      " " +
      detainee.lastname +
      '"/><div class="caption"><h2 class="name">' +
      detainee.firstname +
      " " +
      detainee.lastname +
      '</h2><p class="booktime"><strong>Booked:</strong> ' +
      detainee.disp_arrest_date +
      "</p></div></div></div>";

    // place name, image on page
    $("#inmates").append(inmateBlock);
  } // end inmate data loop

  // process any passed in filter terms
  if (terms != null) {
    terms = decodeURI(terms);

    // update filter input
    $("#filterInput").val(terms);

    // send terms to filter
    // reveal spinner to cue user that filtering is occuring
    $("#filterSpinner").show("fast", function() {
      runFilter(terms);
    });
  }

  // add click listener to get user to mugshot story page
  var url = "detail.html";
  // deploy:
  //var url =
  ("http://www.islandpacket.com/news/local/crime/local-arrests/article157204724.html");

  $(".detaineeIndex").on("click", function() {
    // place inmate data and search terms in localStorage
    var bookingData = {
      inmate: data[$(this).attr("data-index")],
      terms: encodeURI(
        $("#filterInput")
          .val()
          .trim()
          .toLowerCase()
      ),
      start: start,
      end: end
    };
    localStorage.setItem("lexBookingData", JSON.stringify(bookingData));
    $(location).attr({
      href: url,
      target: "_blank"
    });
  }); // end click function

  // now that detainee blocks are on the DOM, add event listeners; we use the .off() to remove any first since displayInmates() is called on sorts and we don't want to add listeners on top of listeners

  // keyup listener for filter input
  $("#filterInput")
    .off()
    .on("keyup", function(event) {
      // clear any existing timeouts if a key is pressed
      clearTimeout(autoRun);

      // grab and normalize value from input
      var value = $(this)
        .val()
        .toLowerCase()
        .trim();

      // if value is "", user has cleared input field; clear timeout, show 'em all and get out
      if (value === "") {
        $(".detaineeIndex").show("fast");
        // update local storage
        var bookingData =
          JSON.parse(localStorage.getItem("lexBookingData")) || {};
        bookingData.terms = "";
        localStorage.setItem("lexBookingData", JSON.stringify(bookingData));
        return;
      }

      //if a space key (32) or enter key (13) is pressed, filter right away
      if (event.which === 32 || event.which === 13) {
        // clear any timeout running
        clearTimeout(autoRun);

        // reveal spinner to cue user that filtering is occuring
        $("#filterSpinner").show("fast", function() {
          runFilter(value);
        });
        return;
      }

      // engage filter if no key is pressed after 1 second
      autoRun = setTimeout(function() {
        // reveal spinner to cue user that filtering is occuring
        $("#filterSpinner").show("fast", function() {
          runFilter(value);
        });
      }, 1000);
    });

  // add clear-filter listener
  $("#clearFilter")
    .off()
    .on("click", function() {
      //clear filter input
      $("#filterInput").val("");
      terms = null;

      // update local storage
      var bookingData =
        JSON.parse(localStorage.getItem("lexBookingData")) || {};
      bookingData.terms = "";
      localStorage.setItem("lexBookingData", JSON.stringify(bookingData));

      // since this is not a keyup operation, we have to programmatically show all detaineeIndex classes
      $(".detaineeIndex").show("fast");
    });

  // add click listeners to sort buttons
  // Alphabetic by detainee last name
  $("#sortAlpha")
    .off()
    .on("click", function() {
      // get current filter terms
      var currentTerms = $("#filterInput")
        .val()
        .trim()
        .toLowerCase();

      // if either type of alpha sort is invoked, set date sort to "desc" and remove sort icon
      $("#sortDate").attr("data-sort", "desc");
      $("#dateSortIcon").removeClass();

      // sorting toggles between asc and desc; what sort are we doing now?
      if ($(this).attr("data-sort") === "desc") {
        // toggle data-sort to desc, change icon and call the sort
        $(this).attr("data-sort", "asc");
        $("#alphaSortIcon")
          .removeClass()
          .addClass("fa fa-arrow-down");
        data.sort(sortAlphaDesc);
        displayInmates(data, start, end, currentTerms);
      } else {
        // toggle data-sort to asc, update icon and call the sort
        $(this).attr("data-sort", "desc");
        $("#alphaSortIcon")
          .removeClass()
          .addClass("fa fa-arrow-up");
        data.sort(sortAlphaAsc);
        displayInmates(data, start, end, currentTerms);
      }
    });

  // Numeric by detainee booking date
  $("#sortDate")
    .off()
    .on("click", function() {
      // get current filter terms
      var currentTerms = $("#filterInput")
        .val()
        .trim()
        .toLowerCase();

      // if either type of date sort is invoked, set alpha sort flag to "asc" and update icon to neutral
      $("#sortAlpha").attr("data-sort", "asc");
      $("#alphaSortIcon").removeClass();

      // determine current sort order and call sort
      if ($(this).attr("data-sort") === "asc") {
        $(this).attr("data-sort", "desc");
        $("#dateSortIcon")
          .removeClass()
          .addClass("fa fa-arrow-up");
        data.sort(sortDateAsc);
        displayInmates(data, start, end, currentTerms);
      } else {
        $(this).attr("data-sort", "asc");
        $("#dateSortIcon")
          .removeClass()
          .addClass("fa fa-arrow-down");
        data.sort(sortDateDesc);
        displayInmates(data, start, end, currentTerms);
      }
    });
} // end displayInmates function

// for story page
function displayInmate(inmate) {
  // we'll need the full name a couple places, so let's build it once:
  inmate.name =
    inmate.firstname + " " + inmate.middlename + " " + inmate.lastname;

  // change browser title and headline to be this inmate and add booking number attribute to inmate div:
  $("#story-header")
    .children(".title")
    .html("Booking details: " + inmate.name);
  $("#inmate").attr("data-booking-number", inmate.book_id);

  // start photo column
  var inmateBlock =
    '<div class="row"><div class="col-lg-6 col-md-6 col-sm-6 col-xs-12">';

  inmateBlock +=
    '<img onerror="this.src=\'http://media.islandpacket.com/static/news/crime/mugshots/noPhoto.jpg\'" class="bcmugshot" src="' +
    inmate.image +
    '" title="' +
    inmate.name +
    '" alt="' +
    inmate.name +
    '" /></div>';

  // set up detail column, including Return button
  inmateBlock +=
    '<div class="col-lg-6 col-md-6 col-sm-6 col-xs-12"><h4>Arrest information <button class="btn btn-primary pull-right" id="returnBtn">Return to Local Arrests</button></h4>';

  // start details table
  inmate.courtNext = inmate.courtNext === "" ? "None listed" : inmate.courtNext;
  inmate.relDate = inmate.relDate === "" ? "None listed" : inmate.relDate;
  inmateBlock +=
    '<table class="details table table-hover table-striped"><tr><th>Arrest date</th><td>' +
    inmate.disp_arrest_date +
    "</td></tr><tr><th>Next court date</th><td>" +
    inmate.courtNext +
    "</td></tr><tr><th>Release date</th><td>" +
    inmate.relDate +
    "</td></tr><tr><th>Date of birth</th><td>" +
    inmate.dob +
    " (age " +
    inmate.age +
    ")</td></tr><tr><th>Race / gender</th><td>" +
    inmate.race +
    ", " +
    inmate.sex +
    "</td></tr><tr><th>Total bond</th><td>" +
    inmate.totalBond +
    "</td></tr></table>";

  // add div at end to hook in details
  inmateBlock +=
    '<div class="row"><div id="arrestDetails" class="col-lg-12"></div></div>';

  // place inmate info in DOM; using .html removes load spinner
  $("#inmate").html(inmateBlock);

  // agency if one listed
  var agency = inmate.disp_agency != "" ? inmate.disp_agency : "None listed";

  // start table for this arrest
  var detailBlock =
    '<table class="table table-hover agencyTable"><tr><th colspan="2" class="text-center">Agency: ' +
    agency +
    "</th><tr><th>Charges/bond details</th><td>";

  // Are there arrest details?
  // API returns charges in an array so test its length:
  if (inmate.charges.length > 0) {
    // loop through arrestinfo
    for (
      var arrestIndex = 0;
      arrestIndex < inmate.charges.length;
      arrestIndex++
    ) {
      //loop through offenses
      detailBlock +=
        '<div class="offense"><span class="leadin">Offense:</span> ' +
        inmate.charges[arrestIndex].Charge +
        "<br/>";

      //create a docket row
      detailBlock +=
        '<span class="leadin">Docket No.:</span> ' +
        inmate.charges[arrestIndex]["Docket #"] +
        "<br/>";

      //create status row
      detailBlock +=
        '<span class="leadin">Status:</span> ' +
        inmate.charges[arrestIndex].Status +
        '<br/><span class="leadin">Bond Amount:</span> ' +
        inmate.charges[arrestIndex]["Bond Amount"] +
        "</div>";
    } // end offenses loop
    // close offenses row
    detailBlock += "</td></tr>";
  } // end charges exist conditional

  // no arrest info is present
  else {
    detailBlock += "No arrest info available</td></tr>";
  }
  // close table
  detailBlock += "</table>";

  // place arrest details in DOM
  $("#arrestDetails").html(detailBlock);

  // add click listener to return button
  // deploy: change href to http://www.islandpacket.com/news/local/crime/local-arrests
  $("#returnBtn").on("click", function() {
    location.href = "index.html";
  });
} // end displayInmate function

// helper functions

/* **************************** */
/*            Filter            */
/* **************************** */

/* loop through stringified data stored in filterSource array and look for matches to passed in string as "value"; if found, show that offender. if not, hide it. 
// we target divs of class ".detaineeIndex" to show/hide by array index (data-index attribute)
*/

function runFilter(term) {
  // update local storage
  var bookingData = JSON.parse(localStorage.getItem("lexBookingData")) || {};
  bookingData.terms = term;
  localStorage.setItem("lexBookingData", JSON.stringify(bookingData));

  // entered just a space? show everyone and get out
  if (!term || term === " ") {
    $(".detaineeIndex").show("fast");
    $("#filterSpinner").hide("fast");
    return;
  }

  // separate terms by spaces, after removing any double spaces
  var terms = term
    .replace(/\s{2,}/g, " ")
    .toLowerCase()
    .split(" ");

  // filterSource is an array of strings of values associated with each detainee
  $(filterSource).each(function(index, detValues) {
    // detValues represents the the value string for each detainee object
    // reset isMatched flag
    var isMatched = false;

    // parse string so we can access a couple values
    var detainee = JSON.parse(detValues);

    // check this detail string against each filter term
    for (var t = 0; t < terms.length; t++) {
      // does the filter input value match any of this detainee's values?
      if (detValues.indexOf(terms[t]) === -1) {
        // no? set isMatched to false, exit loop because there's no need to keep searching
        isMatched = false;
        break;
      }
      // yes?
      //set isMatched to true but keep checking other words in value array
      else {
        isMatched = true;
        // make sure we don't match female with male
        if (terms[t] === "male" && detainee.sex.toLowerCase() === "female") {
          isMatched = false;
        }
      }
    }

    //after checking this element against each term currently in filter, is isMatched still true?
    if (isMatched) {
      $(".detaineeIndex[data-booknum='" + detainee.book_id + "']").show("fast");
    }
    // otherwise, hide it
    else {
      $(".detaineeIndex[data-booknum='" + detainee.book_id + "']").hide("fast");
    }
  });
  // when filtering loop completes, remove spinner from input
  $("#filterSpinner").hide("fast");
}

// sort helpers; expects array of inmate ojects
function sortAlphaAsc(a, b) {
  var keyA = a.lastname;
  var keyB = b.lastname;

  if (keyA < keyB) return -1;
  if (keyA > keyB) return 1;
  return 0;
}

function sortAlphaDesc(a, b) {
  var keyA = a.lastname;
  var keyB = b.lastname;

  if (keyA < keyB) return 1;
  if (keyA > keyB) return -1;
  return 0;
}

function sortDateAsc(a, b) {
  // grab the date portion of the booktime string and turn it into a date
  var keyA = new Date(a.date_arr);
  var keyB = new Date(b.date_arr);
  return keyA - keyB;
}

function sortDateDesc(a, b) {
  // grab the date portion of the booktime string and turn it into a date
  var keyA = new Date(a.date_arr);
  var keyB = new Date(b.date_arr);
  return keyB - keyA;
}

// convert iso date strings to correct local date strings
// from zzzzBov answer at https://stackoverflow.com/questions/7556591/javascript-date-object-always-one-day-off
function toLocaleFromIso(isoDate) {
  // make a date out of the iso formatted date (yyyy-mm-dd)
  var dateString = new Date(isoDate);

  // correct for timezone offset
  dateString.setMinutes(
    dateString.getMinutes() + dateString.getTimezoneOffset()
  );

  // return date string as .toLocaleDateString() formatted string
  return dateString.toLocaleDateString();
}
