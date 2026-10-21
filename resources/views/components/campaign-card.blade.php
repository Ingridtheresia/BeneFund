<article class="card campaign-card">
    <a class="campaign-image" href="{{ route('campaign.show', $campaign) }}" tabindex="-1" aria-hidden="true">
        <img src="{{ $campaign->image_url ?: 'https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=900' }}" alt="" width="640" height="400" loading="lazy">
    </a>
    <div class="card-body">
        <span class="tag">{{ $campaign->category }}</span>
        <h3><a href="{{ route('campaign.show', $campaign) }}">{{ $campaign->title }}</a></h3>
        <p class="campaign-location muted">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
            {{ $campaign->location ?: 'Indonesia' }}
        </p>
        <div class="campaign-funding">
            <div class="progress" role="progressbar" aria-label="Dana terkumpul untuk {{ $campaign->title }}" aria-valuenow="{{ $campaign->percent }}" aria-valuemin="0" aria-valuemax="100">
                <span style="width:{{ $campaign->percent }}%"></span>
            </div>
            <div class="campaign-raised"><b>Rp {{ number_format($campaign->raised, 0, ',', '.') }}</b><small class="muted">{{ $campaign->percent }}%</small></div>
            <p class="campaign-target muted">Target Rp {{ number_format($campaign->target, 0, ',', '.') }}</p>
            <p class="campaign-deadline muted">Berakhir {{ $campaign->deadline->format('d M Y') }}</p>
            <a class="btn outline campaign-link" href="{{ route('campaign.show', $campaign) }}">Lihat Detail <span aria-hidden="true">→</span></a>
        </div>
    </div>
</article>
