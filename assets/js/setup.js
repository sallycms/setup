/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

jQuery(function($) {
	var PasswordStrength = (function() {
		var
			Pw                 = function() { },
			strengthVeryWeak   = 0,
			strengthWeak       = 1,
			strengthFair       = 2,
			strengthStrong     = 3,
			strengthVeryStrong = 4,
			classNames         = {};

		Pw.STRENGTH_VERY_WEAK   = strengthVeryWeak;
		Pw.STRENGTH_WEAK        = strengthWeak;
		Pw.STRENGTH_FAIR        = strengthFair;
		Pw.STRENGTH_STRONG      = strengthStrong;
		Pw.STRENGTH_VERY_STRONG = strengthVeryStrong;

		classNames[strengthVeryWeak]   = 'very-weak';
		classNames[strengthWeak]       = 'weak';
		classNames[strengthFair]       = 'fair';
		classNames[strengthStrong]     = 'strong';
		classNames[strengthVeryStrong] = 'very-strong';

		Pw.prototype = {
			classifyScore: function(score) {
				if (score < 10) return strengthVeryWeak;
				if (score < 60) return strengthWeak;
				if (score < 70) return strengthFair;
				if (score < 90) return strengthVeryStrong;

				return strengthVeryStrong;
			},

			getClassName: function(classID) {
				return classNames[classID];
			},

			getClassText: function(classID) {
				return texts[classID];
			},

			classify: function(pw) {
				return this.classifyScore(this.calculate(pw));
			},

			/**
			 * Calculate score for a password
			 *
			 * @param  string pw  the password to work on
			 * @return int        score
			 */
			calculate: function(pw) {
				var
					length     = pw.length,
					score      = length * 4,
					nUpper     = 0,
					nLower     = 0,
					nNum       = 0,
					nSymbol    = 0,
					locUpper   = [],
					locLower   = [],
					locNum     = [],
					locSymbol  = [],
					locLetters = [],
					charDict   = {},
					i, j, ch, code, reward, repeats, matches, sequences;

				// count character classes
				for (i = 0; i < length; ++i) {
					ch   = pw[i];
					code = ch.charCodeAt(0);

					/* [0-9] */ if      (code >= 48 && code <= 57)  { nNum++;    locNum[locNum.length]       = i; }
					/* [A-Z] */ else if (code >= 65 && code <= 90)  { nUpper++;  locUpper[locUpper.length]   = i; }
					/* [a-z] */ else if (code >= 97 && code <= 122) { nLower++;  locLower[locLower.length]   = i; }
					/* .     */ else                                { nSymbol++; locSymbol[locSymbol.length] = i; }

					if (ch in charDict) {
						charDict[ch]++;
					}
					else {
						charDict[ch] = 1;
					}
				}

				// reward upper/lower characters if pw is not made up of only either one
				if (nUpper !== length && nLower !== length) {
					if (nUpper !== 0) {
						score += (length - nUpper) * 2;
					}

					if (nLower !== 0) {
						score += (length - nLower) * 2;
					}
				}

				// reward numbers if pw is not made up of only numbers
				if (nNum !== length) {
					score += nNum * 4;
				}

				// reward symbols
				score += nSymbol * 6;

				// middle number
				reward = 0;

				for (j = 0; j < locNum.length; ++j) {
					reward += (locNum[j] !== 0 && locNum[j] !== length -1) ? 1 : 0;
				}

				score += reward * 2;

				// middle symbol
				reward = 0;

				for (j = 0; j < locSymbol.length; ++j) {
					reward += (locSymbol[j] !== 0 && locSymbol[j] !== length -1) ? 1 : 0;
				}

				score += reward * 2;

				// chars only
				if (nUpper + nLower === length) {
					score -= length;
				}

				// numbers only
				if (nNum === length) {
					score -= length;
				}

				// repeating chars
				repeats = 0;

				for (j in charDict) {
					if (charDict.hasOwnProperty(j) && charDict[j] > 1) {
						repeats += charDict[j] - 1;
					}
				}

				if (repeats > 0) {
					score -= Math.floor(repeats / (length-repeats)) + 1;
				}

				if (length > 2) {
					// consecutive letters and numbers
					matches = pw.match(/(([a-zA-Z0-9])\2+)/g);

					if (matches) {
						for (j = 0; j < matches.length; ++j) {
							score -= (matches[j].length - 1) * 2;
						}
					}

					// sequential letters
					locLetters = locUpper; // just a reference, but that's ok

					for (j = 0; j < locLower.length; ++j) {
						locLetters[locLetters.length] = locLower[j];
					}

					locLetters.sort();

					sequences = this.findSequence(locLetters, pw.toLowerCase());

					for (j = 0; j < sequences.length; ++j) {
						length = sequences[j].length;

						if (length > 2) {
							score -= (length - 2) * 2;
						}
					}

					// sequential numbers
					sequences = this.findSequence(locNum, pw.toLowerCase());

					for (j = 0; j < sequences.length; ++j) {
						length = sequences[j].length;

						if (length > 2) {
							score -= (length - 2) * 2;
						}
					}
				}

				return score;
			},

			/**
			 * Find all sequential chars in string $src
			 *
			 * Only chars in charLocs are considered. charLocs is a list of numbers.
			 * For example if charLocs is [0,2,3], then only src[2:3] is a possible
			 * substring with sequential chars.
			 *
			 * @param  array  charLocs
			 * @param  string src
			 * @return array             [[c,c,c,c], [a,a,a], ...]
			 */
			findSequence: function(charLocs, src) {
				var sequences = [], sequence = [], i, len, here, next, charHere, charNext, distance, charDistance;

				for (i = 0, len = charLocs.length; i < len-1; ++i) {
					here         = charLocs[i];
					next         = charLocs[i+1];
					charHere     = src[here];
					charNext     = src[next];
					distance     = next - here;
					charDistance = charNext.charCodeAt(0) - charHere.charCodeAt(0);

					if (distance === 1 && charDistance === 1) {
						// We find a pair of sequential chars!
						if (sequence.length === 0) {
							sequence = [charHere, charNext];
						}
						else {
							sequence[sequence.length] = charNext;
						}
					}
					else if (sequence.length > 0) {
						sequences[sequences.length] = sequence;
						sequence                    = [];
					}
				}

				if (sequence.length > 0) {
					sequences[sequences.length] = sequence;
				}

				return sequences;
			}
		};

		return Pw;
	})();

	// forbid clicks on disabled nav elements
	$('.nav .disabled a').on('click', function() {
		return false;
	});

	// enable bootstrap's tooltips
	$('[rel=tooltip]').tooltip();

	// disable host,user,pass when selecting SQLite
	$('#driver').on('change', function() {
		var disable = $(this).val() === 'sqlite';

		$('#host,#username,#password,#create').prop('disabled', disable);
		$('#create').parent().toggle(!disable);
		$('#database').parent().toggleClass('input-append', disable);
		$('.add-on').css({display: disable ? 'inline-block' : 'none'});
	}).trigger('change');

	$('#no_user').on('change', function() {
		$('#username,#userpassword,#gen_password').prop('disabled', this.checked);
	});

	$('input[name="dbaction"]').on('change', function() {
		var requireUser = $(this).val() !== 'nop';

		$('#no_user').prop('disabled', requireUser);
		$('#no_user').parent().toggleClass('muted', requireUser);

		if (requireUser) {
			$('#no_user').prop('checked', false).trigger('change');
		}
	});

	$('input[name="dbaction"]:checked').trigger('change');

	$('#gen_password').on('click', function() {
		var
			letter    = /[a-zA-Z]$/,
			vowel     = /[aeiouAEIOU]$/,
			consonant = /[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]$/,
			password  = function(length, memorable, pattern, prefix) {
				var char, n;

				if (length == null) {
					length = 10;
				}

				if (memorable == null) {
					memorable = true;
				}

				if (pattern == null) {
					pattern = /\w/;
				}

				if (prefix == null) {
					prefix = '';
				}

				if (prefix.length >= length) {
					return prefix;
				}

				if (memorable) {
					if (prefix.match(consonant)) {
						pattern = vowel;
					}
					else {
						pattern = consonant;
					}
				}

				n    = (Math.floor(Math.random() * 100) % 94) + 33;
				char = String.fromCharCode(n);

				if (memorable) {
					char = char.toLowerCase();
				}

				if (!char.match(pattern)) {
					return password(length, memorable, pattern, prefix);
				}

				return password(length, memorable, pattern, "" + prefix + char);
			};

		$('#userpassword').val(password(15, false)).trigger('keyup');
	});

	$('#userpassword').on('keyup', function() {
		var
			password = $(this).val(),
			meter    = new PasswordStrength(),
			score    = meter.classify(password);

		$(this).parent()
			.removeClass('st-unknown st-very-weak st-weak st-fair st-strong st-very-strong')
			.addClass('st-' + meter.getClassName(score))
		;
	}).trigger('keyup');

	$('select').chosen();
});
