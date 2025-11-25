<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $customer->name }} - Brand Guidelines</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background: #ffffff;
            padding: 40px;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 3px solid #3b82f6;
        }

        .header h1 {
            font-size: 32px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 10px;
        }

        .header .subtitle {
            font-size: 18px;
            color: #6b7280;
        }

        .header .meta {
            margin-top: 15px;
            font-size: 14px;
            color: #9ca3af;
        }

        .header .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin: 0 4px;
        }

        .badge-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .badge-info {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .section {
            margin-bottom: 35px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 24px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
        }

        .subsection {
            margin-bottom: 20px;
        }

        .subsection-title {
            font-size: 18px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
        }

        .field-label {
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .field-value {
            font-size: 14px;
            color: #1f2937;
            margin-bottom: 12px;
            padding-left: 12px;
        }

        .color-palette {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }

        .color-swatch {
            display: inline-block;
            width: 60px;
            height: 60px;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            position: relative;
        }

        .color-label {
            font-size: 11px;
            color: #6b7280;
            margin-top: 5px;
            text-align: center;
            font-family: 'Courier New', monospace;
        }

        .tag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 15px;
        }

        .tag {
            display: inline-block;
            padding: 6px 12px;
            background-color: #f3f4f6;
            color: #374151;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
        }

        .tag-warning {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .list {
            list-style-position: inside;
            margin-left: 12px;
            margin-bottom: 12px;
        }

        .list li {
            margin-bottom: 8px;
            font-size: 14px;
            color: #374151;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .quality-score {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .quality-score-value {
            font-size: 48px;
            font-weight: bold;
        }

        .quality-score-label {
            font-size: 14px;
            opacity: 0.9;
        }

        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
        }

        @media print {
            body {
                padding: 20px;
            }
            
            .section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $customer->name }}</h1>
        <div class="subtitle">Brand Guidelines Report</div>
        <div class="meta">
            Generated: {{ now()->format('F j, Y') }}
            @if($brandGuideline->extracted_at)
                | Last Extracted: {{ \Carbon\Carbon::parse($brandGuideline->extracted_at)->format('F j, Y') }}
            @endif
            @if($brandGuideline->user_verified)
                <span class="badge badge-success">✓ Verified</span>
            @endif
            <span class="badge badge-info">Quality: {{ $brandGuideline->quality_score }}/100</span>
        </div>
    </div>

    @if($brandGuideline->quality_score)
    <div class="quality-score">
        <div class="quality-score-value">{{ $brandGuideline->quality_score }}</div>
        <div class="quality-score-label">Brand Guidelines Quality Score</div>
    </div>
    @endif

    <!-- Brand Voice & Tone -->
    @if($brandGuideline->brand_voice)
    <div class="section">
        <h2 class="section-title">🎤 Brand Voice & Tone</h2>
        
        @if(!empty($brandGuideline->brand_voice['primary_tone']))
        <div class="subsection">
            <div class="field-label">Primary Tone</div>
            <div class="field-value">{{ $brandGuideline->brand_voice['primary_tone'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->brand_voice['description']))
        <div class="subsection">
            <div class="field-label">Description</div>
            <div class="field-value">{{ $brandGuideline->brand_voice['description'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->brand_voice['examples']))
        <div class="subsection">
            <div class="field-label">Voice Examples</div>
            <ul class="list">
                @foreach($brandGuideline->brand_voice['examples'] as $example)
                    <li>"{{ $example }}"</li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>
    @endif

    <!-- Tone Attributes -->
    @if($brandGuideline->tone_attributes && count($brandGuideline->tone_attributes) > 0)
    <div class="section">
        <h2 class="section-title">Tone Attributes</h2>
        <div class="tag-list">
            @foreach($brandGuideline->tone_attributes as $tone)
                <span class="tag">{{ $tone }}</span>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Brand Personality -->
    @if($brandGuideline->brand_personality)
    <div class="section">
        <h2 class="section-title">🧑 Brand Personality</h2>
        
        @if(!empty($brandGuideline->brand_personality['archetype']))
        <div class="subsection">
            <div class="field-label">Brand Archetype</div>
            <div class="field-value">{{ $brandGuideline->brand_personality['archetype'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->brand_personality['characteristics']))
        <div class="subsection">
            <div class="field-label">Characteristics</div>
            <div class="tag-list">
                @foreach($brandGuideline->brand_personality['characteristics'] as $characteristic)
                    <span class="tag">{{ $characteristic }}</span>
                @endforeach
            </div>
        </div>
        @endif

        @if(!empty($brandGuideline->brand_personality['if_brand_were_person']))
        <div class="subsection">
            <div class="field-label">If Brand Were a Person</div>
            <div class="field-value">{{ $brandGuideline->brand_personality['if_brand_were_person'] }}</div>
        </div>
        @endif
    </div>
    @endif

    <!-- Color Palette -->
    @if($brandGuideline->color_palette)
    <div class="section">
        <h2 class="section-title">🎨 Color Palette</h2>
        
        @if(!empty($brandGuideline->color_palette['primary_colors']))
        <div class="subsection">
            <div class="field-label">Primary Colors</div>
            <div class="color-palette">
                @foreach($brandGuideline->color_palette['primary_colors'] as $color)
                    <div>
                        <div class="color-swatch" style="background-color: {{ $color }}"></div>
                        <div class="color-label">{{ $color }}</div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        @if(!empty($brandGuideline->color_palette['secondary_colors']))
        <div class="subsection">
            <div class="field-label">Secondary Colors</div>
            <div class="color-palette">
                @foreach($brandGuideline->color_palette['secondary_colors'] as $color)
                    <div>
                        <div class="color-swatch" style="background-color: {{ $color }}"></div>
                        <div class="color-label">{{ $color }}</div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        @if(!empty($brandGuideline->color_palette['description']))
        <div class="subsection">
            <div class="field-label">Color Description</div>
            <div class="field-value">{{ $brandGuideline->color_palette['description'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->color_palette['usage_notes']))
        <div class="subsection">
            <div class="field-label">Usage Notes</div>
            <div class="field-value">{{ $brandGuideline->color_palette['usage_notes'] }}</div>
        </div>
        @endif
    </div>
    @endif

    <!-- Typography -->
    @if($brandGuideline->typography)
    <div class="section">
        <h2 class="section-title">📝 Typography</h2>
        
        @if(!empty($brandGuideline->typography['heading_style']))
        <div class="subsection">
            <div class="field-label">Heading Style</div>
            <div class="field-value">{{ $brandGuideline->typography['heading_style'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->typography['body_style']))
        <div class="subsection">
            <div class="field-label">Body Style</div>
            <div class="field-value">{{ $brandGuideline->typography['body_style'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->typography['fonts_detected']))
        <div class="subsection">
            <div class="field-label">Fonts Detected</div>
            <div class="tag-list">
                @foreach($brandGuideline->typography['fonts_detected'] as $font)
                    <span class="tag">{{ $font }}</span>
                @endforeach
            </div>
        </div>
        @endif

        @if(!empty($brandGuideline->typography['font_weights']))
        <div class="subsection">
            <div class="field-label">Font Weights</div>
            <div class="field-value">{{ $brandGuideline->typography['font_weights'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->typography['letter_spacing']))
        <div class="subsection">
            <div class="field-label">Letter Spacing</div>
            <div class="field-value">{{ $brandGuideline->typography['letter_spacing'] }}</div>
        </div>
        @endif
    </div>
    @endif

    <!-- Visual Style -->
    @if($brandGuideline->visual_style)
    <div class="section">
        <h2 class="section-title">🖼️ Visual Style</h2>
        
        <div class="grid">
            @if(!empty($brandGuideline->visual_style['overall_aesthetic']))
            <div class="subsection">
                <div class="field-label">Overall Aesthetic</div>
                <div class="field-value">{{ $brandGuideline->visual_style['overall_aesthetic'] }}</div>
            </div>
            @endif

            @if(!empty($brandGuideline->visual_style['imagery_style']))
            <div class="subsection">
                <div class="field-label">Imagery Style</div>
                <div class="field-value">{{ $brandGuideline->visual_style['imagery_style'] }}</div>
            </div>
            @endif

            @if(!empty($brandGuideline->visual_style['color_treatment']))
            <div class="subsection">
                <div class="field-label">Color Treatment</div>
                <div class="field-value">{{ $brandGuideline->visual_style['color_treatment'] }}</div>
            </div>
            @endif

            @if(!empty($brandGuideline->visual_style['layout_preference']))
            <div class="subsection">
                <div class="field-label">Layout Preference</div>
                <div class="field-value">{{ $brandGuideline->visual_style['layout_preference'] }}</div>
            </div>
            @endif
        </div>

        @if(!empty($brandGuideline->visual_style['description']))
        <div class="subsection">
            <div class="field-label">Description</div>
            <div class="field-value">{{ $brandGuideline->visual_style['description'] }}</div>
        </div>
        @endif
    </div>
    @endif

    <!-- Messaging Themes -->
    @if($brandGuideline->messaging_themes && count($brandGuideline->messaging_themes) > 0)
    <div class="section">
        <h2 class="section-title">💬 Messaging Themes</h2>
        <ul class="list">
            @foreach($brandGuideline->messaging_themes as $theme)
                <li>{{ $theme }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Unique Selling Propositions -->
    @if($brandGuideline->unique_selling_propositions && count($brandGuideline->unique_selling_propositions) > 0)
    <div class="section">
        <h2 class="section-title">🎯 Unique Selling Propositions</h2>
        <ul class="list">
            @foreach($brandGuideline->unique_selling_propositions as $usp)
                <li>{{ $usp }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Target Audience -->
    @if($brandGuideline->target_audience)
    <div class="section">
        <h2 class="section-title">👥 Target Audience</h2>
        
        @if(!empty($brandGuideline->target_audience['primary']))
        <div class="subsection">
            <div class="field-label">Primary Audience</div>
            <div class="field-value">{{ $brandGuideline->target_audience['primary'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->target_audience['demographics']))
        <div class="subsection">
            <div class="field-label">Demographics</div>
            <div class="field-value">{{ $brandGuideline->target_audience['demographics'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->target_audience['psychographics']))
        <div class="subsection">
            <div class="field-label">Psychographics</div>
            <div class="field-value">{{ $brandGuideline->target_audience['psychographics'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->target_audience['pain_points']))
        <div class="subsection">
            <div class="field-label">Pain Points</div>
            <ul class="list">
                @foreach($brandGuideline->target_audience['pain_points'] as $painPoint)
                    <li>{{ $painPoint }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="grid">
            @if(!empty($brandGuideline->target_audience['language_level']))
            <div class="subsection">
                <div class="field-label">Language Level</div>
                <div class="field-value">{{ $brandGuideline->target_audience['language_level'] }}</div>
            </div>
            @endif

            @if(!empty($brandGuideline->target_audience['familiarity_assumption']))
            <div class="subsection">
                <div class="field-label">Familiarity Assumption</div>
                <div class="field-value">{{ $brandGuideline->target_audience['familiarity_assumption'] }}</div>
            </div>
            @endif
        </div>
    </div>
    @endif

    <!-- Competitor Differentiation -->
    @if($brandGuideline->competitor_differentiation && count($brandGuideline->competitor_differentiation) > 0)
    <div class="section">
        <h2 class="section-title">🎯 Competitor Differentiation</h2>
        <div class="field-label">Key Differentiators</div>
        <ul class="list">
            @foreach($brandGuideline->competitor_differentiation as $differentiator)
                <li>{{ $differentiator }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Do Not Use -->
    @if($brandGuideline->do_not_use && count($brandGuideline->do_not_use) > 0)
    <div class="section">
        <h2 class="section-title">⚠️ Do Not Use</h2>
        <div class="field-label">Restricted Terms & Concepts</div>
        <div class="tag-list">
            @foreach($brandGuideline->do_not_use as $restriction)
                <span class="tag tag-warning">{{ $restriction }}</span>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Writing Patterns -->
    @if($brandGuideline->writing_patterns)
    <div class="section">
        <h2 class="section-title">✍️ Writing Patterns</h2>
        
        @if(!empty($brandGuideline->writing_patterns['sentence_structure']))
        <div class="subsection">
            <div class="field-label">Sentence Structure</div>
            <div class="field-value">{{ $brandGuideline->writing_patterns['sentence_structure'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->writing_patterns['punctuation_style']))
        <div class="subsection">
            <div class="field-label">Punctuation Style</div>
            <div class="field-value">{{ $brandGuideline->writing_patterns['punctuation_style'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->writing_patterns['vocabulary_level']))
        <div class="subsection">
            <div class="field-label">Vocabulary Level</div>
            <div class="field-value">{{ $brandGuideline->writing_patterns['vocabulary_level'] }}</div>
        </div>
        @endif

        @if(!empty($brandGuideline->writing_patterns['common_phrases']))
        <div class="subsection">
            <div class="field-label">Common Phrases</div>
            <ul class="list">
                @foreach($brandGuideline->writing_patterns['common_phrases'] as $phrase)
                    <li>"{{ $phrase }}"</li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>
    @endif

    <div class="footer">
        <p>This brand guidelines report was automatically generated by Spectra</p>
        <p>{{ $customer->website ? $customer->website : '' }}</p>
        <p>© {{ now()->year }} All rights reserved</p>
    </div>
</body>
</html>
