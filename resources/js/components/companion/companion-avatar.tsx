import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import type { CompanionProfile } from '@/types';

export function CompanionAvatar({
    companion,
    className,
}: {
    companion: Pick<CompanionProfile, 'name' | 'avatar_url'>;
    className?: string;
}) {
    return (
        <Avatar className={cn('size-9', className)}>
            {companion.avatar_url && (
                <AvatarImage
                    src={companion.avatar_url}
                    alt={companion.name}
                    className="object-cover"
                />
            )}
            <AvatarFallback className="bg-gradient-to-br from-rose-400 to-fuchsia-500 font-medium text-white">
                {companion.name.charAt(0).toUpperCase()}
            </AvatarFallback>
        </Avatar>
    );
}
